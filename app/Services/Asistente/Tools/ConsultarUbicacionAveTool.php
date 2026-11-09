<?php

namespace App\Services\Asistente\Tools;

use App\Models\Ave;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use Carbon\Carbon;

class ConsultarUbicacionAveTool implements AsistenteToolInterface
{
    public function nombre(): string
    {
        return 'consultar_ubicacion_ave';
    }

    public function descripcion(): string
    {
        return 'Localiza un ave por su código identificador (ej. "AVE-001") o nombre (ej. "Rubia"), retornando su casita, corral, peso y estado.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'criterio' => [
                    'type' => 'STRING',
                    'description' => 'Código identificador o nombre del ave a buscar.',
                ],
            ],
            'required' => ['criterio'],
        ];
    }

    public function ejecutar(array $argumentos, array $contextoFijo = []): array
    {
        $criterio = trim($argumentos['criterio'] ?? '');

        if ($criterio === '') {
            return [
                'encontrado' => false,
                'mensaje' => 'Debe proporcionar un código o nombre de ave para buscar.',
            ];
        }

        $criterioLower = mb_strtolower($criterio);

        $ave = Ave::query()
            ->with(['especie', 'sexo', 'proposito', 'division.casita', 'movimientos.divisionDestino.casita'])
            ->whereRaw('LOWER(codigo) = ?', [$criterioLower])
            ->orWhereRaw('LOWER(nombre) = ?', [$criterioLower])
            ->first();

        if (! $ave) {
            return [
                'encontrado' => false,
                'mensaje' => "No se encontró ningún ave con el identificador o nombre [{$criterio}].",
            ];
        }

        $casita = $ave->division?->casita?->nombre ?? 'Sin casita';
        $division = $ave->division?->nombre ?? 'Sin corral';

        $ultimoMovimiento = $ave->movimientos()->latest('created_at')->first();
        $datosMovimiento = null;

        if ($ultimoMovimiento) {
            $fechaMov = $ultimoMovimiento->fecha instanceof Carbon
                ? $ultimoMovimiento->fecha
                : Carbon::parse($ultimoMovimiento->fecha ?? $ultimoMovimiento->created_at);

            $datosMovimiento = [
                'fecha' => $fechaMov->format('d-m-Y'),
                'motivo' => $ultimoMovimiento->motivo ?? 'Traslado',
                'destino' => $ultimoMovimiento->divisionDestino?->nombre ?? 'Sin destino',
            ];
        }

        return [
            'encontrado' => true,
            'ave' => [
                'codigo' => $ave->codigo,
                'nombre' => $ave->nombre ?? 'Sin nombre',
                'especie' => $ave->especie?->nombre ?? 'No especificada',
                'sexo' => $ave->sexo?->nombre ?? 'No especificado',
                'raza' => $ave->raza ?? 'Criolla',
                'estado' => $ave->estado,
                'peso_actual_kg' => $ave->peso_actual_kg ? (float) $ave->peso_actual_kg : null,
                'ubicacion' => [
                    'casita' => $casita,
                    'division' => $division,
                ],
                'ultimo_movimiento' => $datosMovimiento,
            ],
        ];
    }
}

