<?php

namespace App\Services\Asistente\Tools;

use App\Models\Ave;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use Illuminate\Database\Eloquent\Builder;

class ConsultarInventarioAvesTool implements AsistenteToolInterface
{
    public function nombre(): string
    {
        return 'consultar_inventario_aves';
    }

    public function descripcion(): string
    {
        return 'Consulta la población y el inventario de aves de la granja con filtros opcionales por especie, sexo, corral o estado.';
    }

    public function tablasConsultadas(): array
    {
        return ['mnt_aves', 'ctl_especie', 'ctl_sexo', 'mnt_divisiones', 'mnt_casitas'];
    }

    public function esquema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'especie' => [
                    'type' => 'STRING',
                    'description' => 'Especie del ave (ej. Gallina, Chompipe, Pato).',
                ],
                'sexo' => [
                    'type' => 'STRING',
                    'description' => 'Sexo biológico (Hembra, Macho, Desconocido).',
                ],
                'id_division' => [
                    'type' => 'INTEGER',
                    'description' => 'Identificador numérico de la división o corral.',
                ],
                'estado' => [
                    'type' => 'STRING',
                    'description' => 'Estado del ave: "activa" (por defecto), "salida", o "todos".',
                ],
            ],
        ];
    }

    public function ejecutar(array $argumentos, array $contextoFijo = []): array
    {
        $query = Ave::query()->with(['especie', 'sexo', 'division.casita']);

        $estado = $argumentos['estado'] ?? 'activa';
        if ($estado !== 'todos') {
            $query->where('estado', $estado);
        }

        if (! empty($argumentos['especie'])) {
            $especie = trim($argumentos['especie']);
            $query->whereHas('especie', function (Builder $q) use ($especie) {
                $q->whereRaw('LOWER(nombre) = ?', [mb_strtolower($especie)]);
            });
        }

        if (! empty($argumentos['sexo'])) {
            $sexo = trim($argumentos['sexo']);
            $query->whereHas('sexo', function (Builder $q) use ($sexo) {
                $q->whereRaw('LOWER(nombre) = ?', [mb_strtolower($sexo)]);
            });
        }

        if (! empty($argumentos['id_division'])) {
            $query->where('id_division', (int) $argumentos['id_division']);
        }

        $totalAves = (clone $query)->count();

        // Agrupación por especie
        $aves = (clone $query)->get();

        $porEspecie = [];
        $porSexo = [];

        foreach ($aves as $ave) {
            $esp = $ave->especie?->nombre ?? 'Sin especie';
            $porEspecie[$esp] = ($porEspecie[$esp] ?? 0) + 1;

            $sex = $ave->sexo?->nombre ?? 'Desconocido';
            $porSexo[$sex] = ($porSexo[$sex] ?? 0) + 1;
        }

        // Muestra de hasta 5 aves representativas
        $ejemplos = $aves->take(5)->map(function (Ave $ave) {
            $casita = $ave->division?->casita?->nombre ?? 'Sin casita';
            $division = $ave->division?->nombre ?? 'Sin corral';

            return [
                'codigo' => $ave->codigo,
                'nombre' => $ave->nombre ?? 'Sin nombre',
                'especie' => $ave->especie?->nombre ?? 'No especificada',
                'sexo' => $ave->sexo?->nombre ?? 'No especificado',
                'ubicacion' => "{$casita} - {$division}",
                'peso_actual_kg' => $ave->peso_actual_kg ? (float) $ave->peso_actual_kg : null,
            ];
        })->values()->all();

        return [
            'total_aves' => $totalAves,
            'por_especie' => $porEspecie,
            'por_sexo' => $porSexo,
            'ejemplos' => $ejemplos,
        ];
    }
}
