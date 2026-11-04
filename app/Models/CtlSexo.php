<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlSexo extends Model
{
    use HasFactory;

    protected $table = 'ctl_sexo';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    /**
     * Aves clasificadas bajo este sexo biológico.
     */
    public function aves(): HasMany
    {
        return $this->hasMany(Ave::class, 'id_sexo');
    }
}
