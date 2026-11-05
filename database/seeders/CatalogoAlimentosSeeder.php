<?php

namespace Database\Seeders;

use App\Models\CtlTipoAlimento;
use Illuminate\Database\Seeder;

class CatalogoAlimentosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tipos = [
            [
                'nombre' => 'Concentrado Postura Comercial',
                'descripcion' => 'Alimento balanceado con 16% de proteína y calcio para gallinas ponedoras.',
                'unidad_medida_defecto' => 'kg',
                'activo' => true,
            ],
            [
                'nombre' => 'Maíz Amarillo Quebrado',
                'descripcion' => 'Grano energético seleccionado para complemento de la dieta avícola.',
                'unidad_medida_defecto' => 'kg',
                'activo' => true,
            ],
            [
                'nombre' => 'Concentrado Crecimiento y Engorde',
                'descripcion' => 'Ración de alto contenido proteico ideal para chompipes (pavos) y aves en desarrollo.',
                'unidad_medida_defecto' => 'kg',
                'activo' => true,
            ],
            [
                'nombre' => 'Alimento para Aves Acuáticas / Patos',
                'descripcion' => 'Fórmula digestible adaptada para patos pichiches con requerimientos de niacina.',
                'unidad_medida_defecto' => 'kg',
                'activo' => true,
            ],
            [
                'nombre' => 'Forraje Verde y Hortalizas',
                'descripcion' => 'Suplemento de hojas verdes, zacate tierno y restos de huerto como aporte de fibra.',
                'unidad_medida_defecto' => 'kg',
                'activo' => true,
            ],
        ];

        foreach ($tipos as $tipo) {
            CtlTipoAlimento::updateOrCreate(
                ['nombre' => $tipo['nombre']],
                $tipo
            );
        }
    }
}

