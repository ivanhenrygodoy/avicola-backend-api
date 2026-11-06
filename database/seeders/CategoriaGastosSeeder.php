<?php

namespace Database\Seeders;

use App\Models\CtlCategoriaGasto;
use Illuminate\Database\Seeder;

class CategoriaGastosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categorias = [
            [
                'nombre' => 'Alimento y Forraje',
                'descripcion' => 'Concentrados balanceados, granos, maíz quebrado, forraje y suplementos alimenticios.',
                'activa' => true,
            ],
            [
                'nombre' => 'Medicina y Sanidad',
                'descripcion' => 'Vacunas, vitaminas, electrolitos, desparasitantes, cicatrizantes y antibióticos.',
                'activa' => true,
            ],
            [
                'nombre' => 'Mantenimiento e Instalaciones',
                'descripcion' => 'Viruta fresca, mallas, madera, reparación de techos, nidales, bebederos y comederos.',
                'activa' => true,
            ],
            [
                'nombre' => 'Insumos y Limpieza',
                'descripcion' => 'Cal viva, desinfectantes, detergentes y productos para protocolos de bioseguridad.',
                'activa' => true,
            ],
            [
                'nombre' => 'Herramientas y Equipo',
                'descripcion' => 'Básculas de pesaje, baldes, palas, guantes y equipamiento de manejo avícola.',
                'activa' => true,
            ],
            [
                'nombre' => 'Otros Egresos',
                'descripcion' => 'Gastos varios y operaciones menores de la granja.',
                'activa' => true,
            ],
        ];

        foreach ($categorias as $cat) {
            CtlCategoriaGasto::firstOrCreate(
                ['nombre' => $cat['nombre']],
                $cat
            );
        }
    }
}

