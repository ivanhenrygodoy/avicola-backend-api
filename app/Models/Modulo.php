<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id_modulo_padre', 'clave', 'nombre', 'ruta', 'icono', 'orden',
    'exacto', 'activo', 'solo_super', 'base',
])]
class Modulo extends Model
{
    protected $table = 'mnt_modulos';

    protected function casts(): array
    {
        return [
            'exacto' => 'boolean',
            'activo' => 'boolean',
            'solo_super' => 'boolean',
            'base' => 'boolean',
        ];
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_modulo_padre');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'id_modulo_padre')->orderBy('orden');
    }
}
