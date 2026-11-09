<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MntDocumentoFragmento extends Model
{
    use HasFactory;

    protected $table = 'mnt_documentos_fragmentos';

    protected $primaryKey = 'id_fragmento';

    protected $fillable = [
        'id_documento_tecnico',
        'titulo_seccion',
        'contenido',
        'metadata',
        'embedding',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function documentoTecnico(): BelongsTo
    {
        return $this->belongsTo(CtlDocumentoTecnico::class, 'id_documento_tecnico', 'id_documento_tecnico');
    }
}
