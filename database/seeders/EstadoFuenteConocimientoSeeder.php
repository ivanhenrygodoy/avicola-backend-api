<?php

namespace Database\Seeders;

use App\Models\CtlEstadoFuenteConocimiento;
use Illuminate\Database\Seeder;

class EstadoFuenteConocimientoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $estados = [
            [
                'clave' => CtlEstadoFuenteConocimiento::BORRADOR,
                'nombre' => 'Borrador',
                'descripcion' => 'Documento registrado pero pendiente de fragmentación y vectorización.',
                'color_badge' => 'neutral',
                'activo' => true,
            ],
            [
                'clave' => CtlEstadoFuenteConocimiento::PUBLICADA,
                'nombre' => 'Publicada',
                'descripcion' => 'Fuente vectorizada y activa para las consultas zootécnicas del asistente.',
                'color_badge' => 'success',
                'activo' => true,
            ],
            [
                'clave' => CtlEstadoFuenteConocimiento::ARCHIVADA,
                'nombre' => 'Archivada',
                'descripcion' => 'Fuente retirada del asistente RAG conservando su trazabilidad histórica.',
                'color_badge' => 'warning',
                'activo' => true,
            ],
        ];

        foreach ($estados as $est) {
            CtlEstadoFuenteConocimiento::updateOrCreate(
                ['clave' => $est['clave']],
                $est
            );
        }
    }
}

