<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gasto extends Model
{
    use HasFactory;

    protected $table = 'mnt_gastos';

    protected $fillable = [
        'id_categoria_gasto',
        'id_division',
        'id_usuario',
        'fecha',
        'monto',
        'concepto',
        'proveedor',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:d-m-Y',
            'monto' => 'decimal:2',
        ];
    }

    /**
     * Categoría a la que pertenece el gasto.
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CtlCategoriaGasto::class, 'id_categoria_gasto');
    }

    /**
     * División o corral asociado al gasto (opcional).
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'id_division');
    }

    /**
     * Usuario que registró el gasto.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}

