<?php

namespace Database\Seeders;

use App\Models\CtlTipoInstalacion;
use Illuminate\Database\Seeder;

class CatalogoInstalacionesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tipos = [
            [
                'nombre' => 'Gallinero',
                'descripcion' => 'Instalación techada y acondicionada para gallinas y gallos criollos',
                'activa' => true,
            ],
            [
                'nombre' => 'Mixto',
                'descripcion' => 'Instalación compartida con corrales independientes para múltiples especies',
                'activa' => true,
            ],
            [
                'nombre' => 'Patos',
                'descripcion' => 'Instalación adaptada con acceso a agua para aves acuáticas u ornamentales',
                'activa' => true,
            ],
            [
                'nombre' => 'Galpón',
                'descripcion' => 'Estructura techada para alojamiento general y resguardo nocturno',
                'activa' => true,
            ],
            [
                'nombre' => 'Crianza',
                'descripcion' => 'Espacio protegido para polluelos y etapas tempranas de desarrollo',
                'activa' => true,
            ],
            [
                'nombre' => 'Aislamiento',
                'descripcion' => 'Espacio sanitario para control veterinario y cuarentena preventiva',
                'activa' => true,
            ],
        ];

        foreach ($tipos as $tipo) {
            CtlTipoInstalacion::firstOrCreate(
                ['nombre' => $tipo['nombre']],
                $tipo
            );
        }
    }
}
