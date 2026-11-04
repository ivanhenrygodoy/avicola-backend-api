<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlProposito extends Model
{
    use HasFactory;

    protected $table = 'ctl_proposito';

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
     * Aves con este propósito productivo o zootécnico.
     */
    public function aves(): HasMany
    {
        return $this->hasMany(Ave::class, 'id_proposito');
    }
}
