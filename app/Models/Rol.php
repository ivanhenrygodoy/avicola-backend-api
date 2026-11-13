<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['clave', 'nombre', 'es_super'])]
class Rol extends Model
{
    public const CLAVE_SUPER_ADMIN = 'super_admin';

    public const CLAVE_OPERARIO = 'operario';

    protected $table = 'mnt_roles';

    protected function casts(): array
    {
        return ['es_super' => 'boolean'];
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'mnt_rol_modulo', 'id_rol', 'id_modulo')
            ->withTimestamps();
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'id_rol');
    }
}
