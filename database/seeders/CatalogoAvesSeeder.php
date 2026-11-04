<?php

namespace Database\Seeders;

use App\Models\CtlEspecie;
use App\Models\CtlSexo;
use Illuminate\Database\Seeder;

class CatalogoAvesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Catálogo de Especies (ctl_especie)
        $especies = [
            [
                'nombre' => 'Gallina',
                'descripcion' => 'Gallinas y gallos criollos, ponedoras o reproductores',
                'icono' => '🐔',
                'activa' => true,
            ],
            [
                'nombre' => 'Chompipe',
                'descripcion' => 'Pavos y chompipes criollos autóctonos',
                'icono' => '🦃',
                'activa' => true,
            ],
            [
                'nombre' => 'Pato',
                'descripcion' => 'Patos pichiches, silvestres u ornamentales',
                'icono' => '🦆',
                'activa' => true,
            ],
        ];

        foreach ($especies as $esp) {
            CtlEspecie::firstOrCreate(
                ['nombre' => $esp['nombre']],
                $esp
            );
        }

        // 2. Catálogo de Sexos (ctl_sexo)
        $sexos = [
            [
                'nombre' => 'Hembra',
                'descripcion' => 'Ave hembra',
            ],
            [
                'nombre' => 'Macho',
                'descripcion' => 'Ave macho',
            ],
            [
                'nombre' => 'Desconocido',
                'descripcion' => 'Sexo biológico indeterminado o pendiente de sexado',
            ],
        ];

        foreach ($sexos as $s) {
            CtlSexo::firstOrCreate(
                ['nombre' => $s['nombre']],
                $s
            );
        }
    }
}
