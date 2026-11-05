<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlTipoAlimento extends Model
{
    use HasFactory;

    protected $table = 'ctl_tipos_alimento';

    protected $fillable = [
        'nombre',
        'descripcion',
        'unidad_medida_defecto',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * Registros de alimentación que utilizan este tipo de alimento.
     */
    public function registrosAlimentacion(): HasMany
    {
        return $this->hasMany(RegistroAlimentacion::class, 'id_tipo_alimento');
    }
}

