<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlTipoInstalacion extends Model
{
    use HasFactory;

    protected $table = 'ctl_tipo_instalacion';

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
     * Casitas que poseen este tipo de instalación.
     */
    public function casitas(): HasMany
    {
        return $this->hasMany(Casita::class, 'id_tipo_instalacion');
    }
}
