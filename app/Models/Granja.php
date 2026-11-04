<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Granja extends Model
{
    use HasFactory;

    protected $table = 'mnt_granjas';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    /**
     * Casitas que pertenecen a esta granja.
     */
    public function casitas(): HasMany
    {
        return $this->hasMany(Casita::class, 'id_granja');
    }
}

