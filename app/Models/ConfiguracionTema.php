<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfiguracionTema extends Model
{
    public const MODO_CLARO = 'claro';

    public const MODO_NOCTURNO = 'nocturno';

    public const CONTROL_COLOR = 'color';

    public const CONTROL_SHADOW = 'shadow';

    protected $table = 'mnt_configuracion_tema';

    protected $fillable = [
        'id_componente_tema',
        'modo',
        'aspecto',
        'etiqueta',
        'variable_css',
        'tipo_control',
        'valor_actual',
        'valor_defecto',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'id_componente_tema' => 'integer',
            'orden' => 'integer',
        ];
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(ComponenteTema::class, 'id_componente_tema');
    }

    public function estaPersonalizado(): bool
    {
        return strcasecmp($this->valor_actual, $this->valor_defecto) !== 0;
    }
}

