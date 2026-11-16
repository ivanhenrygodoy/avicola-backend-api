<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlEstadoFuenteConocimiento extends Model
{
    use HasFactory;

    public const BORRADOR = 'borrador';
    public const PUBLICADA = 'publicada';
    public const ARCHIVADA = 'archivada';

    protected $table = 'ctl_estado_fuente_conocimiento';

    protected $primaryKey = 'id';

    protected $fillable = [
        'clave',
        'nombre',
        'descripcion',
        'color_badge',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function documentos(): HasMany
    {
        return $this->hasMany(CtlDocumentoTecnico::class, 'id_estado', 'id');
    }
}

