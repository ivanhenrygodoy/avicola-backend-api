<?php

namespace App\Services\Aves;

use App\Models\Ave;
use App\Models\CtlEspecie;
use App\Models\Division;
use App\Models\MovimientoAve;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TrasladarAveService
{
    /**
     * Traslada un ave a una nueva división garantizando la separación biológica.
     *
     * @param Ave $ave Ave a trasladar
     * @param Division $divisionDestino División a donde se trasladará
     * @param string|null $motivo Motivo del traslado
     * @param string|null $observaciones Observaciones adicionales
     * @param string|null $fecha Fecha del traslado (opcional, default ahora)
     * @return array{ave: Ave, movimiento: MovimientoAve}
     *
     * @throws ValidationException Si se viola alguna regla biológica o de estado
     */
    public function ejecutar(
        Ave $ave,
        Division $divisionDestino,
        ?string $motivo = null,
        ?string $observaciones = null,
        ?string $fecha = null
    ): array {
        // 1. Validar que la división destino esté activa
        if (! $divisionDestino->activa) {
            throw ValidationException::withMessages([
                'id_division_destino' => ["La división destino '{$divisionDestino->nombre}' se encuentra inactiva."],
            ]);
        }

        // 2. Validar que no sea la misma división actual
        if ($ave->id_division === $divisionDestino->id) {
            throw ValidationException::withMessages([
                'id_division_destino' => ["El ave '{$ave->codigo}' ya se encuentra alojada en la división '{$divisionDestino->nombre}'."],
            ]);
        }

        // 3. Regla Biológica Estricta de Separación de Especies (por catálogo ctl_especie)
        $this->validarCompatibilidadBiologica($ave, $divisionDestino);

        // 4. Ejecutar el traslado atómicamente dentro de una transacción
        return DB::transaction(function () use ($ave, $divisionDestino, $motivo, $observaciones, $fecha) {
            $divisionOrigenId = $ave->id_division;

            // Registrar movimiento histórico
            $movimiento = MovimientoAve::create([
                'id_ave' => $ave->id,
                'id_division_origen' => $divisionOrigenId,
                'id_division_destino' => $divisionDestino->id,
                'fecha' => $fecha ?: now(),
                'motivo' => $motivo,
                'observaciones' => $observaciones,
            ]);

            // Actualizar ubicación actual del ave
            $ave->update([
                'id_division' => $divisionDestino->id,
                'estado' => 'activa',
            ]);

            return [
                'ave' => $ave->fresh(['division.casita', 'especie', 'sexo']),
                'movimiento' => $movimiento->load(['divisionOrigen', 'divisionDestino']),
            ];
        });
    }

    /**
     * Valida que la especie del ave sea compatible con las aves presentes en la división destino.
     * En una división avícola sólo pueden convivir aves de la misma especie catalogada (ctl_especie).
     *
     * @throws ValidationException
     */
    public function validarCompatibilidadBiologica(Ave $ave, Division $divisionDestino): void
    {
        // Buscar aves activas en la división destino con una especie distinta a la del ave
        $otraAve = Ave::where('id_division', $divisionDestino->id)
            ->where('id', '!=', $ave->id)
            ->where('estado', 'activa')
            ->where('id_especie', '!=', $ave->id_especie)
            ->with('especie')
            ->first();

        if ($otraAve) {
            $especieDestino = $otraAve->especie?->nombre ?? 'otra especie';
            $especieAve = $ave->especie?->nombre ?? CtlEspecie::find($ave->id_especie)?->nombre ?? 'esta especie';

            throw ValidationException::withMessages([
                'id_division_destino' => [
                    "Incompatibilidad biológica: La división '{$divisionDestino->nombre}' ya alberga aves de especie '{$especieDestino}'. No se permite mezclar con aves de especie '{$especieAve}'.",
                ],
            ]);
        }
    }
}
