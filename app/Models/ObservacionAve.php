<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservacionAve extends Model
{
    use HasFactory;

    protected $table = 'mnt_observaciones_aves';

    protected $fillable = [
        'id_ave',
        'id_usuario',
        'fecha',
        'tipo',
        'titulo',
        'descripcion',
        'accion_tomada',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:d-m-Y',
        ];
    }

    /**
     * Ave a la que corresponde la observación o evento de bitácora.
     */
    public function ave(): BelongsTo
    {
        return $this->belongsTo(Ave::class, 'id_ave');
    }

    /**
     * Usuario que registró la observación.
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}

