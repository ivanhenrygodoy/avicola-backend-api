<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlDocumentoTecnico extends Model
{
    use HasFactory;

    protected $table = 'ctl_documentos_tecnicos';

    protected $primaryKey = 'id_documento_tecnico';

    protected $fillable = [
        'titulo',
        'especie',
        'descripcion',
        'autor',
        'version',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function fragmentos(): HasMany
    {
        return $this->hasMany(MntDocumentoFragmento::class, 'id_documento_tecnico', 'id_documento_tecnico');
    }
}
