<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\Rol;
use Illuminate\Database\Seeder;

/**
 * Roles y módulos iniciales. Idempotente: se puede ejecutar varias veces sin duplicar.
 * Solo asegura los módulos `base` del operario; nunca le retira módulos opcionales
 * (p. ej. `benchmark`) que el superAdmin ya le haya otorgado (D8/R22).
 */
class RolesYModulosSeeder extends Seeder
{
    public function run(): void
    {
        Rol::updateOrCreate(
            ['clave' => Rol::CLAVE_SUPER_ADMIN],
            ['nombre' => 'Super administrador', 'es_super' => true]
        );
        $operario = Rol::updateOrCreate(
            ['clave' => Rol::CLAVE_OPERARIO],
            ['nombre' => 'Operario', 'es_super' => false]
        );

        $modulos = [
            ['clave' => 'dashboard', 'nombre' => 'Dashboard', 'ruta' => '/', 'icono' => 'LayoutDashboard', 'orden' => 1, 'exacto' => true, 'base' => true],
            ['clave' => 'instalaciones', 'nombre' => 'Instalaciones', 'ruta' => '/instalaciones', 'icono' => 'Home', 'orden' => 2, 'base' => true],
            ['clave' => 'aves', 'nombre' => 'Inventario de Aves', 'ruta' => '/aves', 'icono' => 'Layers', 'orden' => 3, 'base' => true],
            ['clave' => 'produccion', 'nombre' => 'Producción', 'ruta' => '/produccion', 'icono' => 'Egg', 'orden' => 4, 'base' => true],
            ['clave' => 'gastos', 'nombre' => 'Gastos', 'ruta' => '/gastos', 'icono' => 'DollarSign', 'orden' => 5, 'base' => true],
            ['clave' => 'asistente', 'nombre' => 'Asistente IA', 'ruta' => null, 'icono' => 'Bot', 'orden' => 6, 'base' => true],
            ['clave' => 'usuarios', 'nombre' => 'Usuarios', 'ruta' => '/usuarios', 'icono' => 'Users', 'orden' => 7, 'solo_super' => true],
            ['clave' => 'permisos', 'nombre' => 'Permisos', 'ruta' => '/permisos', 'icono' => 'ShieldCheck', 'orden' => 8, 'solo_super' => true],
        ];

        foreach ($modulos as $datos) {
            Modulo::updateOrCreate(
                ['clave' => $datos['clave']],
                $datos + ['exacto' => false, 'activo' => true, 'solo_super' => false, 'base' => false]
            );
        }

        $asistente = Modulo::where('clave', 'asistente')->firstOrFail();
        Modulo::updateOrCreate(
            ['clave' => 'benchmark'],
            [
                'id_modulo_padre' => $asistente->id,
                'nombre' => 'Benchmark',
                'ruta' => '/benchmark',
                'icono' => 'Activity',
                'orden' => 1,
                'exacto' => false,
                'activo' => true,
                'solo_super' => false,
                'base' => false,
            ]
        );

        $operario->modulos()->syncWithoutDetaching(
            Modulo::where('base', true)->pluck('id')->all()
        );
    }
}
