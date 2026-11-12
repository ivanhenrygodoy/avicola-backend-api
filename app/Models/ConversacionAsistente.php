<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversación del usuario con el asistente AviaCore. Nunca se borra: se marca como eliminada.
 */
class ConversacionAsistente extends Model
{
    public const ESTADO_ACTIVA = 'activa';

    public const ESTADO_ELIMINADA = 'eliminada';

    public const NOMBRE_PENDIENTE = 'pendiente';

    public const NOMBRE_AUTOMATICO = 'automatico';

    public const NOMBRE_MANUAL = 'manual';

    protected $table = 'mnt_conversaciones_asistente';

    protected $fillable = [
        'id_usuario',
        'uuid',
        'nombre',
        'nombre_origen',
        'estado',
        'eliminada_at',
        'ultima_actividad_at',
    ];

    protected function casts(): array
    {
        return [
            'eliminada_at' => 'datetime',
            'ultima_actividad_at' => 'datetime',
        ];
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeAsistente::class, 'id_conversacion_asistente')->orderBy('id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
