<?php

namespace App\Services;

use App\Models\ComponenteTema;
use App\Models\ConfiguracionTema;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TemaService
{
    /**
     * Devuelve el mapa completo de variables CSS agrupado por modo (`claro` y `nocturno`)
     * para inyectar en el DOM desde el arranque de la SPA.
     *
     * @return array{claro: array<string, string>, nocturno: array<string, string>}
     */
    public function obtenerTemaActivo(): array
    {
        $configs = ConfiguracionTema::query()
            ->whereHas('componente', fn ($q) => $q->where('activo', true))
            ->orderBy('orden')
            ->get();

        $resultado = [
            ConfiguracionTema::MODO_CLARO => [],
            ConfiguracionTema::MODO_NOCTURNO => [],
        ];

        foreach ($configs as $config) {
            $resultado[$config->modo][$config->variable_css] = $config->valor_actual;
        }

        return $resultado;
    }

    /**
     * Listado de componentes configurables con resumen de personalización.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarComponentes(): array
    {
        /** @var Collection<int, ComponenteTema> $componentes */
        $componentes = ComponenteTema::query()
            ->where('activo', true)
            ->with('configuraciones')
            ->orderBy('orden')
            ->get();

        return $componentes->map(fn (ComponenteTema $c) => $this->formatearComponente($c))->values()->all();
    }

    /**
     * Detalle completo de un componente con sus aspectos separados por modo.
     *
     * @return array<string, mixed>
     */
    public function obtenerDetalleComponente(ComponenteTema $componente): array
    {
        $componente->load('configuraciones');

        return $this->formatearComponente($componente, incluirAspectos: true);
    }

    /**
     * Actualiza los valores actuales de un componente para un modo dado dentro de una transacción.
     *
     * @param  array<int, array{aspecto: string, valor_actual: string}>  $aspectos
     * @return array<string, mixed>
     */
    public function actualizarComponente(ComponenteTema $componente, string $modo, array $aspectos): array
    {
        DB::transaction(function () use ($componente, $modo, $aspectos) {
            foreach ($aspectos as $item) {
                $valor = trim($item['valor_actual']);
                if (str_starts_with($valor, '#')) {
                    $valor = strtoupper($valor);
                }

                ConfiguracionTema::query()
                    ->where('id_componente_tema', $componente->id)
                    ->where('modo', $modo)
                    ->where('aspecto', $item['aspecto'])
                    ->update(['valor_actual' => $valor]);
            }
        });

        return $this->obtenerDetalleComponente($componente->fresh());
    }

    /**
     * Restaura un componente a sus valores por defecto (en ambos modos o en uno específico).
     *
     * @return array<string, mixed>
     */
    public function restaurarComponente(ComponenteTema $componente, ?string $modo = null): array
    {
        DB::transaction(function () use ($componente, $modo) {
            $query = ConfiguracionTema::query()->where('id_componente_tema', $componente->id);
            if ($modo !== null) {
                $query->where('modo', $modo);
            }
            $query->update(['valor_actual' => DB::raw('valor_defecto')]);
        });

        return $this->obtenerDetalleComponente($componente->fresh());
    }

    /**
     * Restaura toda la paleta del sistema a los valores oficiales de DESIGN.md.
     */
    public function restaurarTodo(): void
    {
        DB::transaction(function () {
            ConfiguracionTema::query()->update(['valor_actual' => DB::raw('valor_defecto')]);
        });
    }

    /**
     * Persiste la preferencia personal de modo claro/nocturno del usuario autenticado.
     */
    public function actualizarModoUsuario(User $usuario, string $modoTema): string
    {
        $usuario->update(['modo_tema' => $modoTema]);

        return $usuario->modo_tema;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatearComponente(ComponenteTema $componente, bool $incluirAspectos = false): array
    {
        $configs = $componente->configuraciones;
        $personalizadosClaro = $configs->where('modo', ConfiguracionTema::MODO_CLARO)->filter->estaPersonalizado()->count();
        $personalizadosNocturno = $configs->where('modo', ConfiguracionTema::MODO_NOCTURNO)->filter->estaPersonalizado()->count();

        $data = [
            'id' => $componente->id,
            'clave' => $componente->clave,
            'nombre' => $componente->nombre,
            'descripcion' => $componente->descripcion,
            'icono' => $componente->icono,
            'orden' => $componente->orden,
            'total_aspectos' => $configs->where('modo', ConfiguracionTema::MODO_CLARO)->count(),
            'personalizado' => ($personalizadosClaro + $personalizadosNocturno) > 0,
            'personalizados_claro' => $personalizadosClaro,
            'personalizados_nocturno' => $personalizadosNocturno,
        ];

        if ($incluirAspectos) {
            $mapAspecto = fn (ConfiguracionTema $cfg) => [
                'id' => $cfg->id,
                'aspecto' => $cfg->aspecto,
                'etiqueta' => $cfg->etiqueta,
                'variable_css' => $cfg->variable_css,
                'tipo_control' => $cfg->tipo_control,
                'valor_actual' => $cfg->valor_actual,
                'valor_defecto' => $cfg->valor_defecto,
                'personalizado' => $cfg->estaPersonalizado(),
            ];

            $data['aspectos'] = [
                ConfiguracionTema::MODO_CLARO => $configs->where('modo', ConfiguracionTema::MODO_CLARO)->values()->map($mapAspecto)->all(),
                ConfiguracionTema::MODO_NOCTURNO => $configs->where('modo', ConfiguracionTema::MODO_NOCTURNO)->values()->map($mapAspecto)->all(),
            ];
        }

        return $data;
    }
}

