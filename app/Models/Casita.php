<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Casita extends Model
{
    use HasFactory;

    protected $table = 'mnt_casitas';

    protected $fillable = [
        'id_granja',
        'nombre',
        'descripcion',
        'tipo',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * Granja a la que pertenece esta casita.
     */
    public function granja(): BelongsTo
    {
        return $this->belongsTo(Granja::class, 'id_granja');
    }

    /**
     * Divisiones o corrales de esta casita.
     */
    public function divisiones(): HasMany
    {
        return $this->hasMany(Division::class, 'id_casita');
    }
}

