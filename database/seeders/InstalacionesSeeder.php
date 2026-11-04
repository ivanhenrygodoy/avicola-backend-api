<?php

namespace Database\Seeders;

use App\Models\Casita;
use App\Models\Division;
use App\Models\Granja;
use Illuminate\Database\Seeder;

class InstalacionesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Granja base
        $granja = Granja::firstOrCreate(
            ['nombre' => 'Granja Avícola San Antonio'],
            ['descripcion' => 'Granja doméstica para crianza y manejo controlado de aves de corral']
        );

        // 2. Casita 1 (Gallinas y Gallos Criollos)
        $casita1 = Casita::firstOrCreate(
            ['id_granja' => $granja->id, 'nombre' => 'Casita 1'],
            ['descripcion' => 'Instalación principal para gallinas y gallos criollos', 'tipo' => 'Gallinero', 'activa' => true]
        );

        Division::firstOrCreate(
            ['id_casita' => $casita1->id, 'nombre' => 'División 1'],
            ['descripcion' => 'Corral izquierdo para gallinas y gallos criollos', 'activa' => true]
        );

        Division::firstOrCreate(
            ['id_casita' => $casita1->id, 'nombre' => 'División 2'],
            ['descripcion' => 'Corral derecho para gallinas y gallos criollos', 'activa' => true]
        );

        // 3. Casita 2 (Gallinas y Chompipes)
        $casita2 = Casita::firstOrCreate(
            ['id_granja' => $granja->id, 'nombre' => 'Casita 2'],
            ['descripcion' => 'Instalación secundaria con corrales independientes', 'tipo' => 'Mixto', 'activa' => true]
        );

        Division::firstOrCreate(
            ['id_casita' => $casita2->id, 'nombre' => 'División 1'],
            ['descripcion' => 'Corral asignado a gallinas y gallos criollos', 'activa' => true]
        );

        Division::firstOrCreate(
            ['id_casita' => $casita2->id, 'nombre' => 'División 2'],
            ['descripcion' => 'Espacio separado exclusivo para la pareja de chompipes', 'activa' => true]
        );

        // 4. Casita 3 (Patos Pichiches)
        $casita3 = Casita::firstOrCreate(
            ['id_granja' => $granja->id, 'nombre' => 'Casita 3'],
            ['descripcion' => 'Instalación para aves acuáticas / ornamentales', 'tipo' => 'Patos', 'activa' => true]
        );

        Division::firstOrCreate(
            ['id_casita' => $casita3->id, 'nombre' => 'Espacio Pichiches'],
            ['descripcion' => 'Espacio independiente para pareja ornamental de patos pichiches', 'activa' => true]
        );
    }
}

