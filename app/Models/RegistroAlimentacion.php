<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroAlimentacion extends Model
{
    use HasFactory;

    protected $table = 'mnt_registros_alimentacion';

    protected $fillable = [
        'id_division',
        'id_tipo_alimento',
        'id_usuario',
        'fecha',
        'hora',
        'cantidad',
        'unidad_medida',
        'costo_total',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:d-m-Y',
            'cantidad' => 'decimal:2',
            'costo_total' => 'decimal:2',
        ];
    }

    /**
     * División / corral donde se suministró el alimento.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'id_division');
    }

    /**
     * Tipo de alimento suministrado.
     */
    public function tipoAlimento(): BelongsTo
    {
        return $this->belongsTo(CtlTipoAlimento::class, 'id_tipo_alimento');
    }

    /**
     * Usuario que registró la alimentación.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}

