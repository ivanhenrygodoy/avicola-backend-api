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

    /**
     * Registros de alimentación asociados a esta división.
     */
    public function registrosAlimentacion(): HasMany
    {
        return $this->hasMany(RegistroAlimentacion::class, 'id_division');
    }

    /**
     * Registros de postura de huevos recolectados en esta división.
     */
    public function registrosHuevos(): HasMany
    {
        return $this->hasMany(RegistroHuevo::class, 'id_division');
    }

    /**
     * Gastos operativos asignados a esta división.
     */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class, 'id_division');
    }
}

