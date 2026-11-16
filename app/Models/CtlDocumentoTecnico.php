<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtlDocumentoTecnico extends Model
{
    use HasFactory;

    protected $table = 'ctl_documentos_tecnicos';

    protected $primaryKey = 'id';

    protected $fillable = [
        'id_estado',
        'titulo',
        'especie',
        'tipo_fuente',
        'descripcion',
        'autor',
        'version',
        'nombre_archivo_original',
        'ruta_archivo',
        'tamano_bytes',
        'tipo_mime',
        'total_palabras',
        'fecha_procesado',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'tamano_bytes' => 'integer',
        'total_palabras' => 'integer',
        'fecha_procesado' => 'datetime',
    ];

    public function estado(): BelongsTo
    {
        return $this->belongsTo(CtlEstadoFuenteConocimiento::class, 'id_estado', 'id');
    }

    public function fragmentos(): HasMany
    {
        return $this->hasMany(MntDocumentoFragmento::class, 'id_documento_tecnico', 'id');
    }

    /**
     * Scope para filtrar únicamente documentos publicados (utilizables por el RAG).
     */
    public function scopePublicadas(Builder $query): Builder
    {
        return $query->whereHas('estado', function (Builder $q) {
            $q->where('clave', CtlEstadoFuenteConocimiento::PUBLICADA);
        })->where('activo', true);
    }
}
