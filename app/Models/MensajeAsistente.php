<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensaje de una conversación del asistente. El rol usa el mismo vocabulario que Gemini y el frontend.
 */
class MensajeAsistente extends Model
{
    public const UPDATED_AT = null;

    public const ROL_USUARIO = 'user';

    public const ROL_MODELO = 'model';

    protected $table = 'mnt_mensajes_asistente';

    protected $fillable = [
        'id_conversacion_asistente',
        'rol',
        'contenido',
        'herramientas',
        'sugerencias_modulos',
        'es_error',
        'en_contexto',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'herramientas' => 'array',
            'sugerencias_modulos' => 'array',
            'es_error' => 'boolean',
            'en_contexto' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(ConversacionAsistente::class, 'id_conversacion_asistente');
    }
}
