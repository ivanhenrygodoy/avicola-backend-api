<?php

namespace App\Services\Asistente\Conversacion;

use App\Models\ConversacionAsistente;
use App\Models\MensajeAsistente;
use App\Services\Asistente\GeminiApiClient;
use App\Services\Asistente\Guardrails\Salida\SystemLeakageGuard;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pone nombre a una conversación a partir de su primera consulta resuelta y la respuesta del agente.
 *
 * Es una llamada aparte, corta y sin herramientas a Gemini que se pide después del chat para no sumarle
 * latencia. Siempre termina con un nombre: si el modelo falla o devuelve algo inservible, se usa el comienzo
 * de la consulta. Se intenta una sola vez por conversación, de modo que un error del proveedor no genere
 * reintentos. La llamada no se audita en el Benchmark.
 */
class TituloConversacionService
{
    private const LARGO_TEXTO_ENVIADO = 300;

    private const INSTRUCCION = 'Eres un asistente que redacta títulos breves para conversaciones de una granja avícola. '
        .'A partir de la consulta del usuario y la respuesta del asistente, responde ÚNICAMENTE con un título en español '
        .'de 3 a 6 palabras que resuma el tema. Sin comillas, sin punto final, sin explicaciones y en una sola línea.';

    public function __construct(
        private readonly GeminiApiClient $gemini,
        private readonly ConversacionAsistenteService $conversaciones,
        private readonly SystemLeakageGuard $saneador = new SystemLeakageGuard,
    ) {}

    public function generar(ConversacionAsistente $conversacion): ConversacionAsistente
    {
        // Un nombre manual o ya generado nunca se vuelve a calcular (ni se vuelve a llamar al modelo).
        if ($conversacion->nombre_origen !== ConversacionAsistente::NOMBRE_PENDIENTE) {
            return $conversacion;
        }

        [$consulta, $respuesta] = $this->primerTurnoExitoso($conversacion);

        // Sin ningún turno exitoso (solo saludos, bloqueos o errores) no hay de qué nombrarla: no se llama
        // al modelo y queda pendiente, para poder nombrarla cuando llegue un turno exitoso.
        if ($consulta === null) {
            return $conversacion;
        }

        $nombre = $this->proponerConModelo($consulta, (string) $respuesta)
            ?? $this->conversaciones->respaldoDeNombre($conversacion);

        // El UPDATE solo aplica si la conversación sigue pendiente: si mientras el modelo respondía el usuario
        // la renombró (o llegó otra petición que ya la nombró), ese nombre gana y este se descarta.
        ConversacionAsistente::query()
            ->whereKey($conversacion->getKey())
            ->where('nombre_origen', ConversacionAsistente::NOMBRE_PENDIENTE)
            ->update([
                'nombre' => $nombre,
                'nombre_origen' => ConversacionAsistente::NOMBRE_AUTOMATICO,
            ]);

        $conversacion->refresh();

        return $conversacion;
    }

    /**
     * @return array{0: ?string, 1: ?string} consulta del usuario y respuesta del modelo del primer turno exitoso
     */
    private function primerTurnoExitoso(ConversacionAsistente $conversacion): array
    {
        $consulta = $conversacion->mensajes()
            ->where('rol', MensajeAsistente::ROL_USUARIO)
            ->where('en_contexto', true)
            ->orderBy('id')
            ->first();

        if ($consulta === null) {
            return [null, null];
        }

        $respuesta = $conversacion->mensajes()
            ->where('rol', MensajeAsistente::ROL_MODELO)
            ->where('id', '>', $consulta->id)
            ->orderBy('id')
            ->value('contenido');

        return [$consulta->contenido, $respuesta];
    }

    /**
     * El título propuesto por Gemini, ya normalizado, o null si falla o no sirve.
     */
    private function proponerConModelo(string $consulta, string $respuesta): ?string
    {
        try {
            $resultado = $this->gemini->generarContenido([[
                'role' => 'user',
                'parts' => [['text' => "Consulta del usuario:\n".mb_substr($consulta, 0, self::LARGO_TEXTO_ENVIADO)
                    ."\n\nRespuesta del asistente:\n".mb_substr($respuesta, 0, self::LARGO_TEXTO_ENVIADO)]],
            ]], [], self::INSTRUCCION);
        } catch (Throwable $e) {
            Log::warning('[AviaCore] No se pudo generar el nombre de la conversación: '.$e->getMessage());

            return null;
        }

        $texto = '';
        foreach ((array) ($resultado['candidates'][0]['content']['parts'] ?? []) as $parte) {
            $texto .= (string) ($parte['text'] ?? '');
        }

        return $this->normalizar($texto);
    }

    /**
     * Una línea, sin comillas ni punto final, y dentro del largo permitido. Devuelve null si queda vacío,
     * excede el máximo o el saneado lo modifica (señal de rutas, tablas o claves en el texto).
     */
    private function normalizar(string $texto): ?string
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', $texto));

        // Comillas y punto final en cualquier orden (`"Gastos."`, `"Gastos".`, `Gastos."`): se repite hasta
        // que no quede ninguno, porque quitar el punto puede dejar una comilla de cierre al descubierto.
        // Con `/u`: `trim()` con una lista de caracteres trabaja por bytes y rompería letras como «ë» o «á».
        do {
            $antes = $nombre;
            $nombre = (string) preg_replace('/^[\s"\'«»“”‘’`]+|[\s"\'«»“”‘’`]+$/u', '', $nombre);
            $nombre = (string) preg_replace('/[. ]+$/u', '', $nombre);
        } while ($nombre !== $antes);

        if ($nombre === '') {
            return null;
        }

        $maximo = $this->largoMaximo();
        if (mb_strlen($nombre) > $maximo) {
            return null;
        }

        if ($this->saneador->sanear($nombre) !== $nombre) {
            return null;
        }

        return $nombre;
    }

    private function largoMaximo(): int
    {
        $valor = config('asistente.conversacion.nombre_max');

        return is_numeric($valor) && (int) $valor >= 1 ? (int) $valor : 60;
    }
}
