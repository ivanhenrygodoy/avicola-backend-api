<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Herramienta ejecutada durante un turno auditado del asistente.
 */
class FuncionLogAgentTool extends Model
{
    public $timestamps = false;

    protected $table = 'mnt_funcion_log_agent_tools';

    protected $fillable = [
        'id_funcion_log_agent',
        'orden',
        'herramienta',
        'argumentos',
        'tablas_consultadas',
        'resumen_resultado',
        'exito',
        'duracion_ms',
    ];

    protected function casts(): array
    {
        return [
            'argumentos' => 'array',
            'tablas_consultadas' => 'array',
            'exito' => 'boolean',
        ];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(FuncionLogAgent::class, 'id_funcion_log_agent');
    }
}
