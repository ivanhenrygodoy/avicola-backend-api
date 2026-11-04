<?php

namespace Database\Seeders;

use App\Models\CtlEspecie;
use App\Models\CtlProposito;
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

        // 3. Catálogo de Propósitos (ctl_proposito)
        $propositos = [
            [
                'nombre' => 'Postura',
                'descripcion' => 'Producción y recolección regular de huevos',
                'activa' => true,
            ],
            [
                'nombre' => 'Reproducción',
                'descripcion' => 'Pie de cría y reproducción controlada de la raza',
                'activa' => true,
            ],
            [
                'nombre' => 'Ornamental',
                'descripcion' => 'Aves ornamentales, paisajísticas o de exhibición',
                'activa' => true,
            ],
            [
                'nombre' => 'Crianza',
                'descripcion' => 'Crecimiento, desarrollo o engorde',
                'activa' => true,
            ],
            [
                'nombre' => 'Carne',
                'descripcion' => 'Aptitud para aprovechamiento cárnico familiar',
                'activa' => true,
            ],
        ];

        foreach ($propositos as $p) {
            CtlProposito::firstOrCreate(
                ['nombre' => $p['nombre']],
                $p
            );
        }
    }
}
