<?php

namespace Database\Seeders;

use App\Models\Ave;
use App\Models\CtlCategoriaGasto;
use App\Models\Division;
use App\Models\Gasto;
use App\Models\ObservacionAve;
use App\Models\RegistroPeso;
use App\Models\User;
use Illuminate\Database\Seeder;

class GastosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::first();
        $division = Division::first();

        // 1. Semillas de Gastos iniciales
        $catAlimento = CtlCategoriaGasto::where('nombre', 'Alimento y Forraje')->first();
        $catMedicina = CtlCategoriaGasto::where('nombre', 'Medicina y Sanidad')->first();
        $catMantenimiento = CtlCategoriaGasto::where('nombre', 'Mantenimiento e Instalaciones')->first();
        $catInsumos = CtlCategoriaGasto::where('nombre', 'Insumos y Limpieza')->first();

        $gastos = [
            [
                'id_categoria_gasto' => $catAlimento?->id,
                'id_division' => $division?->id,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-11-01',
                'monto' => 38.50,
                'concepto' => 'Saco de 50 lb de Concentrado Postura Premium',
                'proveedor' => 'Agroveterinaria La Campiña',
                'observaciones' => 'Ración para ponedoras de Casita 1',
            ],
            [
                'id_categoria_gasto' => $catMedicina?->id,
                'id_division' => null,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-11-02',
                'monto' => 14.25,
                'concepto' => 'Frasco de Vitaminas A-D-E y Electrolitos solubles',
                'proveedor' => 'Farmacia Veterinaria Central',
                'observaciones' => 'Para refuerzo en agua de bebida ante cambios de temperatura',
            ],
            [
                'id_categoria_gasto' => $catMantenimiento?->id,
                'id_division' => $division?->id,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-11-03',
                'monto' => 12.00,
                'concepto' => '3 sacos de viruta seca y fresca para nidales',
                'proveedor' => 'Aserradero El Pinar',
                'observaciones' => 'Renovación de camas en división ponedoras',
            ],
            [
                'id_categoria_gasto' => $catInsumos?->id,
                'id_division' => null,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-11-04',
                'monto' => 8.50,
                'concepto' => 'Saco de cal hidratada para desinfección perimetral',
                'proveedor' => 'Ferretería El Progreso',
                'observaciones' => 'Tratamiento de pasillos y entrada de casitas',
            ],
            [
                'id_categoria_gasto' => $catAlimento?->id,
                'id_division' => null,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-11-05',
                'monto' => 22.00,
                'concepto' => 'Quintal de maíz amarillo quebrado',
                'proveedor' => 'Molino Santa Lucía',
                'observaciones' => 'Complemento de carbohidratos para pastoreo',
            ],
        ];

        foreach ($gastos as $gasto) {
            if ($gasto['id_categoria_gasto']) {
                Gasto::create($gasto);
            }
        }

        // 2. Semillas de Pesajes iniciales de aves
        $aves = Ave::take(3)->get();
        foreach ($aves as $ave) {
            RegistroPeso::create([
                'id_ave' => $ave->id,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-10-20',
                'peso_kg' => max(0.5, (float) ($ave->peso_actual_kg ?: 1.80) - 0.20),
                'observaciones' => 'Pesaje de control inicial de ingreso',
            ]);

            RegistroPeso::create([
                'id_ave' => $ave->id,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-11-05',
                'peso_kg' => (float) ($ave->peso_actual_kg ?: 1.80),
                'observaciones' => 'Pesaje de rutina quincenal. Desarrollo óptimo.',
            ]);

            ObservacionAve::create([
                'id_ave' => $ave->id,
                'id_usuario' => $admin?->id,
                'fecha' => '2026-11-04',
                'tipo' => 'Salud',
                'titulo' => 'Revisión periódica de plumaje y cresta',
                'descripcion' => 'Cresta roja y turgente, ojos brillantes sin secreciones nasales. Buen apetito y actitud alerta.',
                'accion_tomada' => 'Continuar con dieta habitual y suplemento vitamínico preventivo.',
            ]);
        }
    }
}

