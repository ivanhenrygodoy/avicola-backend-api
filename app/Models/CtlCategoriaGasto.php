<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlCategoriaGasto extends Model
{
    use HasFactory;

    protected $table = 'ctl_categorias_gastos';

    protected $fillable = [
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
     * Gastos asociados a esta categoría.
     */
    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class, 'id_categoria_gasto');
    }
}

