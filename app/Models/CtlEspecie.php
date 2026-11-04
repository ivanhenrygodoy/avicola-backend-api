<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlEspecie extends Model
{
    use HasFactory;

    protected $table = 'ctl_especie';

    protected $fillable = [
        'nombre',
        'descripcion',
        'icono',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * Aves pertenecientes a esta especie.
     */
    public function aves(): HasMany
    {
        return $this->hasMany(Ave::class, 'id_especie');
    }
}
