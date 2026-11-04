<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoAve extends Model
{
    use HasFactory;

    protected $table = 'mnt_movimientos_aves';

    protected $fillable = [
        'id_ave',
        'id_division_origen',
        'id_division_destino',
        'fecha',
        'motivo',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    /**
     * Ave involucrada en el movimiento.
     */
    public function ave(): BelongsTo
    {
        return $this->belongsTo(Ave::class, 'id_ave');
    }

    /**
     * División de procedencia.
     */
    public function divisionOrigen(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'id_division_origen');
    }

    /**
     * División de llegada.
     */
    public function divisionDestino(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'id_division_destino');
    }
}
