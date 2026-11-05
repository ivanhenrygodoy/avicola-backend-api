<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroHuevo extends Model
{
    use HasFactory;

    protected $table = 'mnt_registros_huevos';

    protected $fillable = [
        'id_division',
        'id_usuario',
        'fecha',
        'cantidad_recolectada',
        'cantidad_rotos',
        'cantidad_aprovechables',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'cantidad_recolectada' => 'integer',
            'cantidad_rotos' => 'integer',
            'cantidad_aprovechables' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            $recolectados = (int) ($model->cantidad_recolectada ?? 0);
            $rotos = (int) ($model->cantidad_rotos ?? 0);
            $model->cantidad_aprovechables = max(0, $recolectados - $rotos);
        });
    }

    /**
     * División / corral de donde se recolectó la postura.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'id_division');
    }

    /**
     * Usuario que registró la recolección.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}

