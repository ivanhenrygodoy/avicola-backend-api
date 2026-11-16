<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistente\StoreFuenteConocimientoRequest;
use App\Http\Requests\Asistente\UpdateFuenteConocimientoRequest;
use App\Services\Asistente\Rag\FuenteConocimientoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class FuenteConocimientoController extends Controller
{
    public function __construct(
        private FuenteConocimientoService $fuenteService
    ) {}

    /**
     * Listado paginado de fuentes de conocimiento con filtros y KPIs.
     */
    public function index(Request $request): JsonResponse
    {
        $filtros = [
            'busqueda' => $request->query('busqueda'),
            'especie' => $request->query('especie'),
            'estado' => $request->query('estado'),
        ];

        $perPage = max(1, min(50, (int) $request->query('per_page', 10)));
        $paginador = $this->fuenteService->listar($filtros, $perPage);
        $stats = $this->fuenteService->obtenerMetricas();

        return response()->json([
            'success' => true,
            'data' => $paginador->items(),
            'meta' => [
                'current_page' => $paginador->currentPage(),
                'last_page' => $paginador->lastPage(),
                'per_page' => $paginador->perPage(),
                'total' => $paginador->total(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Muestra el detalle de una fuente y sus fragmentos vectorizados.
     */
    public function show(int $id): JsonResponse
    {
        $documento = $this->fuenteService->obtenerDetalle($id);
        $textoOriginal = $this->fuenteService->obtenerTextoDocumento($documento);

        return response()->json([
            'success' => true,
            'data' => $documento,
            'texto_original' => $textoOriginal,
        ]);
    }

    /**
     * Registra una nueva fuente en borrador o la procesa inmediatamente.
     */
    public function store(StoreFuenteConocimientoRequest $request): JsonResponse
    {
        try {
            $archivo = $request->file('archivo');
            $accion = $request->input('accion', 'borrador');

            $documento = $this->fuenteService->registrar(
                $request->validated(),
                $archivo,
                $accion
            );

            $mensaje = ($accion === 'procesar')
                ? 'Fuente de conocimiento registrada, fragmentada y vectorizada con éxito.'
                : 'Fuente de conocimiento guardada en borrador correctamente.';

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => $documento,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo registrar la fuente de conocimiento: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Actualiza los campos de una fuente en estado borrador y opcionalmente la procesa.
     */
    public function update(UpdateFuenteConocimientoRequest $request, int $id): JsonResponse
    {
        try {
            $documento = $this->fuenteService->obtenerDetalle($id);
            $archivo = $request->file('archivo');
            $accion = $request->input('accion', 'borrador');

            $actualizado = $this->fuenteService->actualizarBorrador(
                $documento,
                $request->validated(),
                $archivo,
                $accion
            );

            $mensaje = ($accion === 'procesar')
                ? "La fuente {$actualizado->titulo} fue actualizada y publicada exitosamente."
                : 'Borrador actualizado correctamente.';

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => $actualizado,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar el borrador: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Procesa e indexa una fuente que se encontraba en borrador.
     */
    public function procesar(int $id): JsonResponse
    {
        try {
            $documento = $this->fuenteService->obtenerDetalle($id);
            $actualizado = $this->fuenteService->procesarDocumento($documento);

            return response()->json([
                'success' => true,
                'message' => "La fuente {$actualizado->titulo} ha sido fragmentada y publicada exitosamente para el asistente.",
                'data' => $actualizado,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la fuente: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Alterna el estado entre Publicada y Archivada (o publica si estaba en borrador).
     */
    public function alternarEstado(int $id): JsonResponse
    {
        try {
            $documento = $this->fuenteService->obtenerDetalle($id);
            $actualizado = $this->fuenteService->alternarEstado($documento);

            $estadoClave = $actualizado->estado?->clave;
            $mensaje = ($estadoClave === 'publicada')
                ? "La fuente {$actualizado->titulo} ahora está publicada y disponible para el asistente."
                : "La fuente {$actualizado->titulo} ha sido archivada y excluida de las búsquedas del asistente.";

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => $actualizado,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al alternar el estado de la fuente: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Descarga segura del archivo fuente original.
     */
    public function descargar(int $id): BinaryFileResponse|JsonResponse
    {
        try {
            $documento = $this->fuenteService->obtenerDetalle($id);
            $archivoInfo = $this->fuenteService->obtenerArchivoParaDescarga($documento);

            return response()->download(
                $archivoInfo['ruta'],
                $archivoInfo['nombre'],
                ['Content-Type' => $archivoInfo['mime']]
            );
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo descargar el archivo: ' . $e->getMessage(),
            ], 404);
        }
    }
}

