<?php

namespace App\Services\Asistente\Conversacion;

use App\Models\ConversacionAsistente;
use App\Models\MensajeAsistente;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conversaciones del usuario con el asistente: crea, busca, lista, renombra y "elimina" (cambia el estado,
 * nunca borra), arma el contexto que se reenvía al modelo y guarda cada turno.
 *
 * Toda búsqueda se resuelve contra el usuario autenticado: un UUID ajeno, inexistente, eliminado o con
 * formato inválido es indistinguible (devuelve null), igual que el contexto fijo anti-IDOR del chat.
 */
class ConversacionAsistenteService
{
    private const NOMBRE_POR_DEFECTO = 'Conversación';

    private const LARGO_VISTA_PREVIA = 90;

    private const LARGO_RESPALDO_NOMBRE = 50;

    /**
     * La conversación activa del usuario con ese UUID, o null.
     */
    public function buscar(int $idUsuario, ?string $uuid): ?ConversacionAsistente
    {
        if ($uuid === null || ! Str::isUuid($uuid)) {
            return null;
        }

        return ConversacionAsistente::query()
            ->where('uuid', strtolower($uuid))
            ->where('id_usuario', $idUsuario)
            ->where('estado', ConversacionAsistente::ESTADO_ACTIVA)
            ->first();
    }

    /**
     * La conversación propia con ese UUID o, si no existe, una nueva con UUID generado por el servidor.
     * Un UUID ajeno nunca se reutiliza: se crea otra conversación.
     */
    public function obtenerOCrear(int $idUsuario, ?string $uuid): ConversacionAsistente
    {
        return $this->buscar($idUsuario, $uuid) ?? $this->crear($idUsuario);
    }

    /**
     * Mensajes que se reenvían al modelo, en el formato de Gemini. Solo los marcados `en_contexto`
     * (turnos exitosos), los últimos N, sin ventana de tiempo, y siempre empezando por uno del usuario.
     *
     * @return array<int, array{role: string, parts: array<int, array{text: string}>}>
     */
    public function contexto(ConversacionAsistente $conversacion): array
    {
        $mensajes = MensajeAsistente::query()
            ->where('id_conversacion_asistente', $conversacion->id)
            ->where('en_contexto', true)
            ->orderByDesc('id')
            ->limit($this->entero('contexto_mensajes', 6))
            ->get(['rol', 'contenido'])
            ->reverse()
            ->values();

        // Si el recorte dejó al modelo hablando primero, se descartan los mensajes iniciales hasta
        // que el contexto empiece con el usuario.
        while ($mensajes->isNotEmpty() && $mensajes->first()->rol !== MensajeAsistente::ROL_USUARIO) {
            $mensajes->shift();
        }

        return $mensajes
            ->map(fn (MensajeAsistente $m) => ['role' => $m->rol, 'parts' => [['text' => $m->contenido]]])
            ->values()
            ->all();
    }

    /**
     * Guarda un turno completo (mensaje del usuario y respuesta del modelo, en ese orden). Solo los turnos
     * exitosos entran al contexto: saludos, bloqueos de guards y errores se guardan y se muestran, pero no
     * se reenvían al modelo. Ambos mensajes del turno comparten bandera para que el contexto nunca quede
     * con un turno incompleto.
     *
     * @param  array<int, string>  $herramientas
     * @param  array<int, array<string, mixed>>  $sugerencias
     */
    public function registrarTurno(
        ConversacionAsistente $conversacion,
        string $mensajeUsuario,
        string $textoModelo,
        string $resultado,
        array $herramientas = [],
        array $sugerencias = [],
    ): void {
        $esError = $resultado === 'error';
        $enContexto = $resultado === 'exitoso';

        DB::transaction(function () use ($conversacion, $mensajeUsuario, $textoModelo, $esError, $enContexto, $herramientas, $sugerencias) {
            $conversacion->mensajes()->create([
                'rol' => MensajeAsistente::ROL_USUARIO,
                'contenido' => $mensajeUsuario,
                'es_error' => $esError,
                'en_contexto' => $enContexto,
                'created_at' => now(),
            ]);

            $conversacion->mensajes()->create([
                'rol' => MensajeAsistente::ROL_MODELO,
                'contenido' => $textoModelo,
                'herramientas' => $herramientas === [] ? null : array_values($herramientas),
                'sugerencias_modulos' => $sugerencias === [] ? null : array_values($sugerencias),
                'es_error' => $esError,
                'en_contexto' => $enContexto,
                'created_at' => now(),
            ]);

            $conversacion->forceFill(['ultima_actividad_at' => now()])->save();
        });
    }

    /**
     * Conversaciones activas del usuario, las más recientes primero. No expone ids ni datos de usuario.
     *
     * @return array<int, array{uuid: string, nombre: string, vista_previa: string, ultima_actividad: string, total_mensajes: int}>
     */
    public function listar(int $idUsuario): array
    {
        $zona = $this->zona();

        return ConversacionAsistente::query()
            ->where('id_usuario', $idUsuario)
            ->where('estado', ConversacionAsistente::ESTADO_ACTIVA)
            ->withCount('mensajes')
            ->orderByDesc('ultima_actividad_at')
            ->orderByDesc('id')
            ->limit($this->entero('listado_max', 50))
            ->get()
            ->map(fn (ConversacionAsistente $c) => [
                'uuid' => $c->uuid,
                'nombre' => $this->nombreVisible($c),
                'vista_previa' => $this->vistaPrevia($c),
                'ultima_actividad' => CarbonImmutable::parse($c->ultima_actividad_at ?? $c->created_at)->setTimezone($zona)->format('d-m-Y h:i A'),
                'total_mensajes' => (int) $c->mensajes_count,
            ])
            ->all();
    }

    /**
     * Los últimos mensajes de la conversación, en orden ascendente, listos para pintar el chat.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mensajes(ConversacionAsistente $conversacion): array
    {
        return MensajeAsistente::query()
            ->where('id_conversacion_asistente', $conversacion->id)
            ->orderByDesc('id')
            ->limit($this->entero('mensajes_max_carga', 100))
            ->get()
            ->reverse()
            ->values()
            ->map(fn (MensajeAsistente $m) => [
                'id' => $m->id,
                'rol' => $m->rol,
                'contenido' => $m->contenido,
                'herramientas' => $m->herramientas ?? [],
                'sugerencias_modulos' => $m->sugerencias_modulos ?? [],
                'es_error' => $m->es_error,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function renombrar(ConversacionAsistente $conversacion, string $nombre): ConversacionAsistente
    {
        $conversacion->update([
            'nombre' => $nombre,
            'nombre_origen' => ConversacionAsistente::NOMBRE_MANUAL,
        ]);

        return $conversacion;
    }

    /**
     * "Elimina" la conversación: solo cambia su estado. Los mensajes y la conversación se conservan.
     */
    public function eliminar(ConversacionAsistente $conversacion): void
    {
        $conversacion->update([
            'estado' => ConversacionAsistente::ESTADO_ELIMINADA,
            'eliminada_at' => now(),
        ]);
    }

    /**
     * Nombre que ve el usuario: el guardado o, si todavía no tiene (conversaciones sin ningún turno
     * exitoso, o cuyo nombre automático aún no llega), "Conversación". No hay nombre provisional.
     */
    public function nombreVisible(ConversacionAsistente $conversacion): string
    {
        $nombre = trim((string) $conversacion->nombre);

        return $nombre !== '' ? $nombre : self::NOMBRE_POR_DEFECTO;
    }

    /**
     * Texto que se guarda como nombre cuando Gemini no pudo proponer uno: el comienzo de la primera
     * consulta del usuario, en una línea, hasta 50 caracteres y cortado en palabra.
     */
    public function respaldoDeNombre(ConversacionAsistente $conversacion): string
    {
        $primera = $conversacion->mensajes()
            ->where('rol', MensajeAsistente::ROL_USUARIO)
            ->orderBy('id')
            ->value('contenido');

        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $primera));
        if ($texto === '') {
            return self::NOMBRE_POR_DEFECTO;
        }

        if (mb_strlen($texto) <= self::LARGO_RESPALDO_NOMBRE) {
            return $texto;
        }

        $corte = mb_substr($texto, 0, self::LARGO_RESPALDO_NOMBRE + 1);
        $ultimoEspacio = mb_strrpos($corte, ' ');
        $cortado = $ultimoEspacio !== false && $ultimoEspacio > 10
            ? mb_substr($corte, 0, $ultimoEspacio)
            : mb_substr($texto, 0, self::LARGO_RESPALDO_NOMBRE);

        // `/u`: `rtrim()` con lista de caracteres trabaja por bytes y dejaría UTF-8 inválido tras «á», «ñ», «¿»...
        return (string) preg_replace('/[\s,;:.\-¿¡]+$/u', '', $cortado);
    }

    /**
     * Seudónimo del UUID de la conversación para la bitácora de auditoría: determinista (todos los turnos
     * de una conversación comparten seudónimo) pero imposible de enlazar con la tabla de conversaciones
     * sin la clave de la aplicación. Seudonimización, no anonimato absoluto.
     */
    public function uuidAuditoria(ConversacionAsistente $conversacion): string
    {
        $hex = substr(hash_hmac('sha256', $conversacion->uuid, (string) config('app.key')), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function crear(int $idUsuario): ConversacionAsistente
    {
        // El estado y el origen del nombre se fijan aquí y no se dejan al valor por defecto de la base de
        // datos: el modelo recién creado no los lee de vuelta, y el chat decide `requiere_titulo` con ellos.
        $datos = fn () => [
            'id_usuario' => $idUsuario,
            'uuid' => (string) Str::uuid(),
            'estado' => ConversacionAsistente::ESTADO_ACTIVA,
            'nombre_origen' => ConversacionAsistente::NOMBRE_PENDIENTE,
            'ultima_actividad_at' => now(),
        ];

        try {
            return ConversacionAsistente::create($datos());
        } catch (UniqueConstraintViolationException) {
            // Colisión de UUID (prácticamente imposible): un único reintento con otro UUID.
            return ConversacionAsistente::create($datos());
        }
    }

    private function vistaPrevia(ConversacionAsistente $conversacion): string
    {
        $primera = $conversacion->mensajes()
            ->where('rol', MensajeAsistente::ROL_USUARIO)
            ->orderBy('id')
            ->value('contenido');

        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $primera));

        return mb_strlen($texto) > self::LARGO_VISTA_PREVIA
            ? rtrim(mb_substr($texto, 0, self::LARGO_VISTA_PREVIA)).'…'
            : $texto;
    }

    /**
     * Valor entero positivo de la configuración; si es cero, negativo o no numérico, el valor por defecto.
     */
    private function entero(string $clave, int $porDefecto): int
    {
        $valor = config("asistente.conversacion.{$clave}");

        return is_numeric($valor) && (int) $valor >= 1 ? (int) $valor : $porDefecto;
    }

    private function zona(): string
    {
        return (string) config('avicola.timezone', 'America/El_Salvador');
    }
}
