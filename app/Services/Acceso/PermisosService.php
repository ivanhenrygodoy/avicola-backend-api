<?php

namespace App\Services\Acceso;

use App\Models\Modulo;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Qué módulos puede ver un usuario y cómo se los presentamos al frontend.
 */
class PermisosService
{
    /**
     * Lista plana de claves permitidas (Ruling 5): el chat se decide con esta lista,
     * porque `asistente` es a la vez grupo del menú y permiso.
     *
     * @return array<int, string>
     */
    public function clavesPermitidas(User $usuario): array
    {
        return array_values($usuario->clavesPermitidas());
    }

    /**
     * Árbol de menú ordenado por `orden`. Un grupo sin ruta aparece solo si tiene
     * algún hijo visible (p. ej. «Asistente IA» sin Benchmark no se muestra).
     *
     * @return array<int, array{clave: string, nombre: string, ruta: ?string, icono: ?string, exacto: bool, hijos: array}>
     */
    public function arbolPara(User $usuario): array
    {
        $permitidas = array_flip($usuario->clavesPermitidas());

        if ($permitidas === []) {
            return [];
        }

        $modulos = Modulo::where('activo', true)->orderBy('orden')->get();
        $hijosPorPadre = $modulos->whereNotNull('id_modulo_padre')->groupBy('id_modulo_padre');

        $arbol = [];
        foreach ($modulos->whereNull('id_modulo_padre') as $modulo) {
            $hijos = [];
            foreach ($hijosPorPadre->get($modulo->id, collect()) as $hijo) {
                if (isset($permitidas[$hijo->clave])) {
                    $hijos[] = $this->nodo($hijo, []);
                }
            }

            $esGrupo = $modulo->ruta === null;

            if ($esGrupo ? $hijos === [] : ! isset($permitidas[$modulo->clave])) {
                continue;
            }

            $arbol[] = $this->nodo($modulo, $hijos);
        }

        return $arbol;
    }

    /**
     * Datos de acceso que acompañan al usuario en `login` y `me` (R5).
     *
     * @return array<string, mixed>
     */
    public function datosDeSesion(User $usuario): array
    {
        $usuario->loadMissing('rol');

        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'email' => $usuario->email,
            'rol' => $usuario->rol
                ? ['clave' => $usuario->rol->clave, 'nombre' => $usuario->rol->nombre]
                : null,
            'modulos' => $this->arbolPara($usuario),
            'permisos' => $this->clavesPermitidas($usuario),
            'debe_cambiar_password' => (bool) $usuario->debe_cambiar_password,
        ];
    }

    /**
     * Matriz rol × módulo para la pantalla de gestión de permisos (solo superAdmin).
     *
     * @return array{roles: array<int, array<string, mixed>>, modulos: array<int, array<string, mixed>>}
     */
    public function matriz(): array
    {
        $roles = Rol::with('modulos')->orderBy('id')->get()->map(fn (Rol $rol) => [
            'id' => $rol->id,
            'clave' => $rol->clave,
            'nombre' => $rol->nombre,
            'es_super' => $rol->es_super,
            'modulos' => $rol->modulos->pluck('clave')->values()->all(),
        ])->all();

        $modulos = Modulo::with('padre')->orderBy('orden')->get()->map(fn (Modulo $modulo) => [
            'clave' => $modulo->clave,
            'nombre' => $modulo->nombre,
            'padre' => $modulo->padre?->clave,
            'solo_super' => $modulo->solo_super,
            'base' => $modulo->base,
        ])->all();

        return ['roles' => $roles, 'modulos' => $modulos];
    }

    /**
     * Otorga a un rol los módulos opcionales indicados y retira los demás opcionales.
     * Los módulos `base` se conservan siempre (D8/R22); un `solo_super` nunca se asigna.
     *
     * @param  array<int, string>  $claves
     *
     * @throws ValidationException
     */
    public function sincronizar(Rol $rol, array $claves): void
    {
        if ($rol->es_super) {
            throw ValidationException::withMessages([
                'rol' => ['El rol super administrador no se puede editar: tiene acceso a todo por regla.'],
            ]);
        }

        $modulos = Modulo::whereIn('clave', $claves)->get()->keyBy('clave');

        foreach ($claves as $indice => $clave) {
            if (! $modulos->has($clave)) {
                throw ValidationException::withMessages([
                    "modulos.$indice" => ["El módulo «{$clave}» no existe."],
                ]);
            }
            if ($modulos[$clave]->solo_super) {
                throw ValidationException::withMessages([
                    "modulos.$indice" => ["El módulo «{$clave}» es exclusivo del super administrador."],
                ]);
            }
        }

        $opcionales = $modulos->filter(fn (Modulo $m) => ! $m->base)->pluck('id')->all();
        $base = Modulo::where('base', true)->pluck('id')->all();

        DB::transaction(fn () => $rol->modulos()->sync(array_values(array_unique([...$base, ...$opcionales]))));
    }

    private function nodo(Modulo $modulo, array $hijos): array
    {
        return [
            'clave' => $modulo->clave,
            'nombre' => $modulo->nombre,
            'ruta' => $modulo->ruta,
            'icono' => $modulo->icono,
            'exacto' => $modulo->exacto,
            'hijos' => $hijos,
        ];
    }
}
