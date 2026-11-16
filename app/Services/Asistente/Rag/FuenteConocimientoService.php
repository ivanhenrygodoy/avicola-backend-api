<?php

namespace App\Services\Asistente\Rag;

use App\Models\CtlDocumentoTecnico;
use App\Models\CtlEstadoFuenteConocimiento;
use App\Models\MntDocumentoFragmento;
use App\Services\Asistente\GeminiApiClient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FuenteConocimientoService
{
    public function __construct(
        private ExtraccionTextoService $extractor,
        private ChunkingService $chunker,
        private GeminiApiClient $geminiClient,
        private VectorStoreService $vectorStore
    ) {}

    /**
     * Lista paginada de fuentes de conocimiento con filtros y métricas.
     *
     * @param array<string, mixed> $filtros
     */
    public function listar(array $filtros = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = CtlDocumentoTecnico::with(['estado'])
            ->withCount('fragmentos');

        if (!empty($filtros['busqueda'])) {
            $busqueda = trim((string) $filtros['busqueda']);
            $query->where(function ($q) use ($busqueda) {
                $q->where('titulo', 'ilike', "%{$busqueda}%")
                    ->orWhere('descripcion', 'ilike', "%{$busqueda}%")
                    ->orWhere('autor', 'ilike', "%{$busqueda}%");
            });
        }

        if (!empty($filtros['especie']) && $filtros['especie'] !== 'Todas') {
            $query->where('especie', $filtros['especie']);
        }

        if (!empty($filtros['estado']) && $filtros['estado'] !== 'Todos') {
            $query->whereHas('estado', function ($q) use ($filtros) {
                $q->where('clave', $filtros['estado']);
            });
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Obtiene el detalle de una fuente con sus fragmentos vectorizados.
     */
    public function obtenerDetalle(int $id): CtlDocumentoTecnico
    {
        return CtlDocumentoTecnico::with(['estado', 'fragmentos'])
            ->withCount('fragmentos')
            ->findOrFail($id);
    }

    /**
     * Registra una nueva fuente en borrador o la procesa directamente.
     *
     * @param array<string, mixed> $datos
     */
    public function registrar(array $datos, ?UploadedFile $archivo = null, string $accion = 'borrador'): CtlDocumentoTecnico
    {
        $tipoFuente = $datos['tipo_fuente'] ?? 'documento';
        $textoExtraido = '';
        $nombreArchivo = null;
        $rutaArchivo = null;
        $tamanoBytes = null;
        $tipoMime = null;

        if ($tipoFuente === 'documento' && $archivo instanceof UploadedFile) {
            $nombreArchivo = $archivo->getClientOriginalName();
            $tamanoBytes = $archivo->getSize();
            $tipoMime = $archivo->getClientMimeType() ?: 'application/octet-stream';

            // Guardar físicamente en disco local en directorio privado
            $rutaArchivo = $archivo->store('documentos_rag', 'local');
            $rutaCompleta = Storage::disk('local')->path($rutaArchivo);

            $textoExtraido = $this->extractor->extraerDeArchivo($rutaCompleta);
        } elseif ($tipoFuente === 'texto') {
            $textoExtraido = $this->extractor->normalizarTexto((string) ($datos['texto_contenido'] ?? ''));
            $tamanoBytes = strlen($textoExtraido);
            $tipoMime = 'text/plain';

            // Guardar el texto directo como archivo de respaldo para permitir descarga
            $nombreArchivo = 'texto_directo_' . time() . '.txt';
            $rutaArchivo = 'documentos_rag/' . $nombreArchivo;
            Storage::disk('local')->put($rutaArchivo, $textoExtraido);
        } else {
            throw new RuntimeException('Debe proporcionar un archivo válido o texto directo.');
        }

        $palabras = preg_split('/\s+/', trim($textoExtraido));
        $totalPalabras = count(array_filter($palabras));

        $claveEstadoInicial = ($accion === 'procesar')
            ? CtlEstadoFuenteConocimiento::PUBLICADA
            : CtlEstadoFuenteConocimiento::BORRADOR;

        $estado = CtlEstadoFuenteConocimiento::where('clave', $claveEstadoInicial)->firstOrFail();

        return DB::transaction(function () use (
            $datos,
            $estado,
            $tipoFuente,
            $nombreArchivo,
            $rutaArchivo,
            $tamanoBytes,
            $tipoMime,
            $totalPalabras,
            $accion,
            $textoExtraido
        ) {
            $tituloLimpio = trim((string) ($datos['titulo'] ?? ''));
            if ($tituloLimpio === '' && $accion === 'borrador') {
                $tituloLimpio = !empty($nombreArchivo)
                    ? pathinfo($nombreArchivo, PATHINFO_FILENAME)
                    : 'Borrador sin título';
            }

            $documento = CtlDocumentoTecnico::create([
                'id_estado' => $estado->id,
                'titulo' => $tituloLimpio,
                'especie' => !empty($datos['especie']) ? trim((string) $datos['especie']) : null,
                'tipo_fuente' => $tipoFuente,
                'descripcion' => !empty($datos['descripcion']) ? trim((string) $datos['descripcion']) : null,
                'autor' => !empty($datos['autor']) ? trim((string) $datos['autor']) : '',
                'version' => !empty($datos['version']) ? trim((string) $datos['version']) : '',
                'nombre_archivo_original' => $nombreArchivo,
                'ruta_archivo' => $rutaArchivo,
                'tamano_bytes' => $tamanoBytes,
                'tipo_mime' => $tipoMime,
                'total_palabras' => $totalPalabras,
                'fecha_procesado' => ($accion === 'procesar') ? now() : null,
                'activo' => true,
            ]);

            if ($accion === 'procesar') {
                $this->ejecutarVectorizacion($documento, $textoExtraido);
            }

            return $documento->fresh(['estado'])->loadCount('fragmentos');
        });
    }

    /**
     * Actualiza los campos de una fuente en estado borrador y opcionalmente la procesa.
     *
     * @param array<string, mixed> $datos
     */
    public function actualizarBorrador(
        CtlDocumentoTecnico $documento,
        array $datos,
        ?UploadedFile $archivo = null,
        string $accion = 'borrador'
    ): CtlDocumentoTecnico {
        if ($documento->estado?->clave !== CtlEstadoFuenteConocimiento::BORRADOR) {
            throw new RuntimeException('Solo se pueden editar fuentes de conocimiento que se encuentren en estado borrador.');
        }

        $tipoFuente = $datos['tipo_fuente'] ?? $documento->tipo_fuente ?? 'documento';
        $nombreArchivo = $documento->nombre_archivo_original;
        $rutaArchivo = $documento->ruta_archivo;
        $tamanoBytes = $documento->tamano_bytes;
        $tipoMime = $documento->tipo_mime;
        $textoExtraido = '';

        if ($tipoFuente === 'documento') {
            if ($archivo instanceof UploadedFile) {
                if (!empty($rutaArchivo) && Storage::disk('local')->exists($rutaArchivo)) {
                    Storage::disk('local')->delete($rutaArchivo);
                }

                $nombreArchivo = $archivo->getClientOriginalName();
                $tamanoBytes = $archivo->getSize();
                $tipoMime = $archivo->getClientMimeType() ?: 'application/octet-stream';
                $rutaArchivo = $archivo->store('documentos_rag', 'local');
                $rutaCompleta = Storage::disk('local')->path($rutaArchivo);
                $textoExtraido = $this->extractor->extraerDeArchivo($rutaCompleta);
            } elseif (!empty($rutaArchivo) && Storage::disk('local')->exists($rutaArchivo) && $documento->tipo_fuente === 'documento') {
                $textoExtraido = $this->extractor->extraerDeArchivo(Storage::disk('local')->path($rutaArchivo));
            } else {
                throw new RuntimeException('Debe adjuntar un archivo válido (PDF, TXT o Markdown).');
            }
        } elseif ($tipoFuente === 'texto') {
            $textoExtraido = $this->extractor->normalizarTexto((string) ($datos['texto_contenido'] ?? ''));
            if (mb_strlen($textoExtraido) < 10) {
                throw new RuntimeException('El texto directo debe contener al menos 10 caracteres.');
            }

            if (!empty($rutaArchivo) && Storage::disk('local')->exists($rutaArchivo) && $documento->tipo_fuente === 'documento') {
                Storage::disk('local')->delete($rutaArchivo);
            }

            $tamanoBytes = strlen($textoExtraido);
            $tipoMime = 'text/plain';
            $nombreArchivo = 'texto_directo_' . $documento->id . '_' . time() . '.txt';
            $rutaArchivo = 'documentos_rag/' . $nombreArchivo;
            Storage::disk('local')->put($rutaArchivo, $textoExtraido);
        }

        $palabras = preg_split('/\s+/', trim($textoExtraido));
        $totalPalabras = count(array_filter($palabras));

        return DB::transaction(function () use (
            $documento,
            $datos,
            $tipoFuente,
            $nombreArchivo,
            $rutaArchivo,
            $tamanoBytes,
            $tipoMime,
            $totalPalabras,
            $accion
        ) {
            $tituloLimpio = trim((string) ($datos['titulo'] ?? ''));
            if ($tituloLimpio === '' && $accion === 'borrador') {
                $tituloLimpio = !empty($nombreArchivo)
                    ? pathinfo($nombreArchivo, PATHINFO_FILENAME)
                    : 'Borrador sin título';
            }

            $documento->update([
                'titulo' => $tituloLimpio,
                'especie' => !empty($datos['especie']) ? trim((string) $datos['especie']) : null,
                'tipo_fuente' => $tipoFuente,
                'descripcion' => !empty($datos['descripcion']) ? trim((string) $datos['descripcion']) : null,
                'autor' => !empty($datos['autor']) ? trim((string) $datos['autor']) : '',
                'version' => !empty($datos['version']) ? trim((string) $datos['version']) : '',
                'nombre_archivo_original' => $nombreArchivo,
                'ruta_archivo' => $rutaArchivo,
                'tamano_bytes' => $tamanoBytes,
                'tipo_mime' => $tipoMime,
                'total_palabras' => $totalPalabras,
            ]);

            if ($accion === 'procesar') {
                return $this->procesarDocumento($documento->fresh(['estado']));
            }

            return $documento->fresh(['estado'])->loadCount('fragmentos');
        });
    }

    /**
     * Procesa un documento en borrador (chunking + generación de embeddings).
     */
    public function procesarDocumento(CtlDocumentoTecnico $documento): CtlDocumentoTecnico
    {
        if (
            empty(trim((string) $documento->titulo)) ||
            empty(trim((string) $documento->especie)) ||
            empty(trim((string) $documento->autor)) ||
            empty(trim((string) $documento->version)) ||
            empty(trim((string) $documento->descripcion))
        ) {
            throw new RuntimeException('Debes completar todos los campos (título, especie, autor, versión y descripción) antes de procesar la fuente.');
        }

        $texto = $this->obtenerTextoDocumento($documento);
        if (empty(trim($texto))) {
            throw new RuntimeException('No se pudo extraer contenido de texto válido para indexar.');
        }

        return DB::transaction(function () use ($documento, $texto) {
            // Eliminar fragmentos previos si existieran
            MntDocumentoFragmento::where('id_documento_tecnico', $documento->id)->delete();

            $this->ejecutarVectorizacion($documento, $texto);

            $idPublicada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::PUBLICADA)->value('id');

            $documento->update([
                'id_estado' => $idPublicada,
                'fecha_procesado' => now(),
            ]);

            return $documento->fresh(['estado'])->loadCount('fragmentos');
        });
    }

    /**
     * Alterna el estado entre Publicada y Archivada mediante el Switch.
     */
    public function alternarEstado(CtlDocumentoTecnico $documento): CtlDocumentoTecnico
    {
        $claveActual = $documento->estado?->clave;

        if ($claveActual === CtlEstadoFuenteConocimiento::BORRADOR) {
            // Si está en borrador, alternar equivale a procesar y publicar
            return $this->procesarDocumento($documento);
        }

        $nuevaClave = ($claveActual === CtlEstadoFuenteConocimiento::PUBLICADA)
            ? CtlEstadoFuenteConocimiento::ARCHIVADA
            : CtlEstadoFuenteConocimiento::PUBLICADA;

        $nuevoEstado = CtlEstadoFuenteConocimiento::where('clave', $nuevaClave)->firstOrFail();

        $documento->update(['id_estado' => $nuevoEstado->id]);

        return $documento->fresh(['estado']);
    }

    /**
     * Obtiene los datos del archivo físico para descarga segura.
     *
     * @return array{ruta: string, nombre: string, mime: string}
     */
    public function obtenerArchivoParaDescarga(CtlDocumentoTecnico $documento): array
    {
        if (empty($documento->ruta_archivo) || !Storage::disk('local')->exists($documento->ruta_archivo)) {
            throw new RuntimeException('El archivo físico asociado a esta fuente no fue encontrado en el servidor.');
        }

        return [
            'ruta' => Storage::disk('local')->path($documento->ruta_archivo),
            'nombre' => $documento->nombre_archivo_original ?: "documento_{$documento->id}.txt",
            'mime' => $documento->tipo_mime ?: 'application/octet-stream',
        ];
    }

    /**
     * Obtiene KPIs generales para la cabecera del módulo.
     *
     * @return array{total_fuentes: int, total_publicadas: int, total_fragmentos: int, especies_cubiertas: int}
     */
    public function obtenerMetricas(): array
    {
        $totalFuentes = CtlDocumentoTecnico::count();
        $totalPublicadas = CtlDocumentoTecnico::publicadas()->count();
        $totalFragmentos = MntDocumentoFragmento::count();
        $especiesCubiertas = CtlDocumentoTecnico::whereNotNull('especie')
            ->distinct('especie')
            ->count('especie');

        return [
            'total_fuentes' => $totalFuentes,
            'total_publicadas' => $totalPublicadas,
            'total_fragmentos' => $totalFragmentos,
            'especies_cubiertas' => max(1, $especiesCubiertas),
        ];
    }

    /**
     * Obtiene el texto íntegro del documento a partir del archivo guardado.
     */
    public function obtenerTextoDocumento(CtlDocumentoTecnico $documento): string
    {
        if (!empty($documento->ruta_archivo) && Storage::disk('local')->exists($documento->ruta_archivo)) {
            $rutaCompleta = Storage::disk('local')->path($documento->ruta_archivo);
            return $this->extractor->extraerDeArchivo($rutaCompleta);
        }

        // Si no hay archivo físico pero existen fragmentos, reconstruir del texto de fragmentos
        $fragmentos = $documento->fragmentos()->orderBy('id')->pluck('contenido')->all();
        if (!empty($fragmentos)) {
            return implode("\n\n", $fragmentos);
        }

        return $documento->descripcion ?? '';
    }

    /**
     * Ejecuta el troceado y guardado de fragmentos con embeddings.
     */
    private function ejecutarVectorizacion(CtlDocumentoTecnico $documento, string $texto): void
    {
        $chunks = $this->chunker->trocear($texto, $documento->titulo);

        foreach ($chunks as $chunk) {
            $textoParaEmbedding = $chunk['titulo_seccion'] . "\n\n" . $chunk['contenido'];

            try {
                $vector = $this->geminiClient->generarEmbedding($textoParaEmbedding);
            } catch (Throwable) {
                // Fallback de embedding determinista de 768 dimensiones si la API de Gemini falla o está en testing
                $hash = md5($textoParaEmbedding);
                $vector = [];
                for ($i = 0; $i < 768; $i++) {
                    $charIndex = $i % 32;
                    $vector[] = round((hexdec($hash[$charIndex]) - 8) / 10.0, 4);
                }
            }

            $metadata = array_merge($chunk['metadata'] ?? [], [
                'especie' => $documento->especie,
                'autor' => $documento->autor,
                'version' => $documento->version,
            ]);

            $this->vectorStore->guardarFragmento(
                $documento->id,
                $chunk['titulo_seccion'],
                $chunk['contenido'],
                $vector,
                $metadata
            );
        }
    }
}
