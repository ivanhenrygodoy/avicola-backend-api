<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponenteTema extends Model
{
    protected $table = 'ctl_componentes_tema';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'icono',
        'orden',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function configuraciones(): HasMany
    {
        return $this->hasMany(ConfiguracionTema::class, 'id_componente_tema')->orderBy('orden');
    }
}

