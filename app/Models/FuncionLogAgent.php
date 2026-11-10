<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno auditado del asistente AviaCore. No referencia al usuario (confidencialidad).
 */
class FuncionLogAgent extends Model
{
    public const UPDATED_AT = null;

    public const RESULTADO_EXITOSO = 'exitoso';

    public const RESULTADO_BLOQUEADO = 'bloqueado_guard';

    public const RESULTADO_ERROR = 'error';

    public const RESULTADO_FAST_PATH = 'fast_path';

    protected $table = 'mnt_funcion_log_agent';

    protected $fillable = [
        'uuid_conversacion',
        'mensaje_usuario',
        'turnos_historial',
        'prompt_version',
        'respuesta_agente',
        'respuesta_original_llm',
        'resultado',
        'guards_aplicados',
        'guards_nombres',
        'error_mensaje',
        'tokens_prompt',
        'tokens_respuesta',
        'tokens_total',
        'iteraciones',
        'duracion_ms',
        'modelo',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'guards_aplicados' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tools(): HasMany
    {
        return $this->hasMany(FuncionLogAgentTool::class, 'id_funcion_log_agent')->orderBy('orden');
    }
}
