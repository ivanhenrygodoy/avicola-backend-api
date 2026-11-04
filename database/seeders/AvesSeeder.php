<?php

namespace Database\Seeders;

use App\Models\Ave;
use App\Models\Casita;
use App\Models\CtlEspecie;
use App\Models\CtlProposito;
use App\Models\CtlSexo;
use App\Models\Division;
use App\Models\MovimientoAve;
use Illuminate\Database\Seeder;

class AvesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Obtener catálogos
        $espGallina = CtlEspecie::where('nombre', 'Gallina')->firstOrFail();
        $espChompipe = CtlEspecie::where('nombre', 'Chompipe')->firstOrFail();
        $espPato = CtlEspecie::where('nombre', 'Pato')->firstOrFail();

        $sexoHembra = CtlSexo::where('nombre', 'Hembra')->firstOrFail();
        $sexoMacho = CtlSexo::where('nombre', 'Macho')->firstOrFail();

        $propPostura = CtlProposito::where('nombre', 'Postura')->firstOrFail();
        $propReproduccion = CtlProposito::where('nombre', 'Reproducción')->firstOrFail();
        $propOrnamental = CtlProposito::where('nombre', 'Ornamental')->firstOrFail();

        // 2. Obtener casitas y divisiones de referencia
        $casita1 = Casita::where('nombre', 'Casita 1')->first();
        $casita2 = Casita::where('nombre', 'Casita 2')->first();
        $casita3 = Casita::where('nombre', 'Casita 3')->first();

        $c1d1 = Division::where('id_casita', $casita1?->id)->where('nombre', 'División 1')->first();
        $c1d2 = Division::where('id_casita', $casita1?->id)->where('nombre', 'División 2')->first();
        $c2d1 = Division::where('id_casita', $casita2?->id)->where('nombre', 'División 1')->first();
        $c2d2 = Division::where('id_casita', $casita2?->id)->where('nombre', 'División 2')->first();
        $c3pichiches = Division::where('id_casita', $casita3?->id)->where('nombre', 'Espacio Pichiches')->first();

        $avesData = [
            // --- CASITA 1: DIVISIÓN 1 (Gallinas y Gallo Criollo) ---
            [
                'codigo' => 'AVE-001',
                'nombre' => 'Canela',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c1d1?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 2.15,
                'observaciones' => 'Gallina ponedora marrón con excelente docilidad.',
            ],
            [
                'codigo' => 'AVE-002',
                'nombre' => 'Blanca',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c1d1?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 2.05,
                'observaciones' => 'Plumaje blanco puro, buena tasa de postura.',
            ],
            [
                'codigo' => 'AVE-003',
                'nombre' => 'Pintada',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c1d1?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 2.20,
                'observaciones' => 'Plumaje manchado blanco y negro.',
            ],
            [
                'codigo' => 'AVE-004',
                'nombre' => 'Cresta Roja',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoMacho->id,
                'id_proposito' => $propReproduccion->id,
                'raza' => 'Criollo',
                'id_division' => $c1d1?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 3.10,
                'observaciones' => 'Gallo reproductor dominante de la División 1.',
            ],

            // --- CASITA 1: DIVISIÓN 2 (Gallinas y Gallo Criollo) ---
            [
                'codigo' => 'AVE-005',
                'nombre' => 'Ceniza',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c1d2?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 2.10,
                'observaciones' => 'Plumaje gris cenizo.',
            ],
            [
                'codigo' => 'AVE-006',
                'nombre' => 'Mora',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c1d2?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 1.95,
                'observaciones' => 'Tono oscuro azulado en plumaje.',
            ],
            [
                'codigo' => 'AVE-007',
                'nombre' => 'Perla',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c1d2?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 2.25,
                'observaciones' => 'Plumaje claro perlado.',
            ],
            [
                'codigo' => 'AVE-008',
                'nombre' => 'Carioco',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoMacho->id,
                'id_proposito' => $propReproduccion->id,
                'raza' => 'Criollo',
                'id_division' => $c1d2?->id,
                'fecha_ingreso' => '2026-01-15',
                'estado' => 'activa',
                'peso_actual_kg' => 2.90,
                'observaciones' => 'Gallo criollo cuello desnudo carioco.',
            ],

            // --- CASITA 2: DIVISIÓN 1 (Gallinas Ponedoras Separadas) ---
            [
                'codigo' => 'AVE-009',
                'nombre' => 'Rubia',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c2d1?->id,
                'fecha_ingreso' => '2026-01-20',
                'estado' => 'activa',
                'peso_actual_kg' => 2.30,
                'observaciones' => 'Una de las dos gallinas ponedoras en seguimiento especial.',
            ],
            [
                'codigo' => 'AVE-010',
                'nombre' => 'Negra',
                'id_especie' => $espGallina->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propPostura->id,
                'raza' => 'Criolla',
                'id_division' => $c2d1?->id,
                'fecha_ingreso' => '2026-01-20',
                'estado' => 'activa',
                'peso_actual_kg' => 2.18,
                'observaciones' => 'Segunda gallina ponedora en seguimiento especial.',
            ],

            // --- CASITA 2: DIVISIÓN 2 (Pareja de Chompipes) ---
            [
                'codigo' => 'AVE-011',
                'nombre' => 'Pavo Real',
                'id_especie' => $espChompipe->id,
                'id_sexo' => $sexoMacho->id,
                'id_proposito' => $propReproduccion->id,
                'raza' => 'Criolla Autóctona',
                'id_division' => $c2d2?->id,
                'fecha_ingreso' => '2026-01-10',
                'estado' => 'activa',
                'peso_actual_kg' => 5.80,
                'observaciones' => 'Chompipe macho reproductor, excelente temperamento.',
            ],
            [
                'codigo' => 'AVE-012',
                'nombre' => 'Pavita',
                'id_especie' => $espChompipe->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propReproduccion->id,
                'raza' => 'Criolla Autóctona',
                'id_division' => $c2d2?->id,
                'fecha_ingreso' => '2026-01-10',
                'estado' => 'activa',
                'peso_actual_kg' => 4.20,
                'observaciones' => 'Chompipe hembra reproductora.',
            ],

            // --- CASITA 3: ESPACIO PICHICHES (Pareja Ornamental Patos Pichiches) ---
            [
                'codigo' => 'AVE-013',
                'nombre' => 'Pato Lucas',
                'id_especie' => $espPato->id,
                'id_sexo' => $sexoMacho->id,
                'id_proposito' => $propOrnamental->id,
                'raza' => 'Pichiche Silvestre',
                'id_division' => $c3pichiches?->id,
                'fecha_ingreso' => '2026-01-05',
                'estado' => 'activa',
                'peso_actual_kg' => 1.45,
                'observaciones' => 'Pato pichiche macho ornamental, adaptado al estanque.',
            ],
            [
                'codigo' => 'AVE-014',
                'nombre' => 'Pata Daisy',
                'id_especie' => $espPato->id,
                'id_sexo' => $sexoHembra->id,
                'id_proposito' => $propOrnamental->id,
                'raza' => 'Pichiche Silvestre',
                'id_division' => $c3pichiches?->id,
                'fecha_ingreso' => '2026-01-05',
                'estado' => 'activa',
                'peso_actual_kg' => 1.30,
                'observaciones' => 'Pata pichiche hembra ornamental.',
            ],
        ];

        foreach ($avesData as $data) {
            $ave = Ave::firstOrCreate(
                ['codigo' => $data['codigo']],
                $data
            );

            // Registrar movimiento de ingreso inicial si no existe
            if ($ave->wasRecentlyCreated && $ave->id_division) {
                MovimientoAve::firstOrCreate(
                    [
                        'id_ave' => $ave->id,
                        'id_division_destino' => $ave->id_division,
                        'id_division_origen' => null,
                    ],
                    [
                        'fecha' => $data['fecha_ingreso'] ?: now(),
                        'motivo' => 'Ingreso inicial a la granja',
                        'observaciones' => 'Registro de inventario inicial (Población base de 14 aves)',
                    ]
                );
            }
        }
    }
}
