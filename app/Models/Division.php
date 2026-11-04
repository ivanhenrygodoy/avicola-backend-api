<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    use HasFactory;

    protected $table = 'mnt_divisiones';

    protected $fillable = [
        'id_casita',
        'nombre',
        'descripcion',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /**
     * Casita a la que pertenece esta división.
     */
    public function casita(): BelongsTo
    {
        return $this->belongsTo(Casita::class, 'id_casita');
    }

    /**
     * Aves actualmente alojadas en esta división.
     */
    public function aves(): HasMany
    {
        return $this->hasMany(Ave::class, 'id_division');
    }
}

