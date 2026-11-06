<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ave extends Model
{
    use HasFactory;

    protected $table = 'mnt_aves';

    protected $fillable = [
        'id_division',
        'id_especie',
        'id_sexo',
        'id_proposito',
        'codigo',
        'nombre',
        'raza',
        'fecha_nacimiento',
        'fecha_ingreso',
        'estado',
        'peso_actual_kg',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'fecha_ingreso' => 'date',
            'peso_actual_kg' => 'decimal:2',
        ];
    }

    /**
     * Especie catalogada del ave.
     */
    public function especie(): BelongsTo
    {
        return $this->belongsTo(CtlEspecie::class, 'id_especie');
    }

    /**
     * Sexo biológico catalogado del ave.
     */
    public function sexo(): BelongsTo
    {
        return $this->belongsTo(CtlSexo::class, 'id_sexo');
    }

    /**
     * Propósito zootécnico o productivo catalogado del ave.
     */
    public function proposito(): BelongsTo
    {
        return $this->belongsTo(CtlProposito::class, 'id_proposito');
    }

    /**
     * División o corral donde se encuentra actualmente el ave.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'id_division');
    }

    /**
     * Historial de traslados y movimientos del ave.
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoAve::class, 'id_ave')->orderBy('fecha', 'desc');
    }

    /**
     * Historial de registros de peso del ave.
     */
    public function pesos(): HasMany
    {
        return $this->hasMany(RegistroPeso::class, 'id_ave')->orderBy('fecha', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Bitácora de observaciones y notas sanitarias del ave.
     */
    public function observaciones(): HasMany
    {
        return $this->hasMany(ObservacionAve::class, 'id_ave')->orderBy('fecha', 'desc')->orderBy('id', 'desc');
    }

    /**
     * Scope para filtrar aves activas.
     */
    public function scopeActiva($query)
    {
        return $query->where('estado', 'activa');
    }

    /**
     * Scope para filtrar por ID de especie.
     */
    public function scopePorEspecie($query, $idEspecie)
    {
        if ($idEspecie) {
            return $query->where('id_especie', $idEspecie);
        }
        return $query;
    }
}
