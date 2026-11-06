<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroPeso extends Model
{
    use HasFactory;

    protected $table = 'mnt_registros_peso';

    protected $fillable = [
        'id_ave',
        'id_usuario',
        'fecha',
        'peso_kg',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:d-m-Y',
            'peso_kg' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (self $model) {
            // Sincronizar el peso actual del ave con el pesaje más reciente
            $ultimoPeso = self::where('id_ave', $model->id_ave)
                ->orderBy('fecha', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if ($ultimoPeso) {
                Ave::where('id', $model->id_ave)->update([
                    'peso_actual_kg' => $ultimoPeso->peso_kg,
                ]);
            }
        });

        static::deleted(function (self $model) {
            // Recalcular el peso actual del ave al eliminar un registro
            $ultimoPeso = self::where('id_ave', $model->id_ave)
                ->orderBy('fecha', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            Ave::where('id', $model->id_ave)->update([
                'peso_actual_kg' => $ultimoPeso ? $ultimoPeso->peso_kg : null,
            ]);
        });
    }

    /**
     * Ave a la que pertenece el pesaje.
     */
    public function ave(): BelongsTo
    {
        return $this->belongsTo(Ave::class, 'id_ave');
    }

    /**
     * Usuario que registró el pesaje.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
