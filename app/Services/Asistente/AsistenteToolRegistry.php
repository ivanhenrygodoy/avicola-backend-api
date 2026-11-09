<?php

namespace App\Services\Asistente;

use App\Services\Asistente\Contracts\AsistenteToolInterface;
use InvalidArgumentException;

/**
 * Registro y despachador central de herramientas autorizadas para el Asistente de IA.
 */
class AsistenteToolRegistry
{
    /**
     * @var array<string, AsistenteToolInterface>
     */
    private array $herramientas = [];

    /**
     * @param  iterable<AsistenteToolInterface>  $herramientas
     */
    public function __construct(iterable $herramientas = [])
    {
        foreach ($herramientas as $herramienta) {
            $this->registrar($herramienta);
        }
    }

    /**
     * Registra una nueva herramienta en el registro.
     */
    public function registrar(AsistenteToolInterface $herramienta): self
    {
        $this->herramientas[$herramienta->nombre()] = $herramienta;

        return $this;
    }

    /**
     * Comprueba si una herramienta está registrada por nombre.
     */
    public function tiene(string $nombre): bool
    {
        return isset($this->herramientas[$nombre]);
    }

    /**
     * Obtiene la instancia de una herramienta por su nombre.
     */
    public function obtener(string $nombre): AsistenteToolInterface
    {
        if (! $this->tiene($nombre)) {
            throw new InvalidArgumentException("La herramienta [{$nombre}] no está registrada.");
        }

        return $this->herramientas[$nombre];
    }

    /**
     * Retorna todas las declaraciones de herramientas en formato OpenAPI para Gemini Function Calling.
     *
     * @return array<int, array<string, mixed>>
     */
    public function obtenerDeclaracionesTools(): array
    {
        $declaraciones = [];

        foreach ($this->herramientas as $herramienta) {
            $declaraciones[] = [
                'name' => $herramienta->nombre(),
                'description' => $herramienta->descripcion(),
                'parameters' => $herramienta->esquema(),
            ];
        }

        return $declaraciones;
    }

    /**
     * Ejecuta una herramienta por nombre inyectando argumentos del modelo y contexto fijo de sesión.
     *
     * @param  array<string, mixed>  $argumentos
     * @param  array<string, mixed>  $contextoFijo
     * @return array<string, mixed>
     */
    public function ejecutar(string $nombre, array $argumentos, array $contextoFijo = []): array
    {
        return $this->obtener($nombre)->ejecutar($argumentos, $contextoFijo);
    }
}

