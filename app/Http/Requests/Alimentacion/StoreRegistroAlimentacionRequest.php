<?php

namespace App\Http\Requests\Alimentacion;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistroAlimentacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_division' => ['required', 'integer', 'exists:mnt_divisiones,id'],
            'id_tipo_alimento' => ['required', 'integer', 'exists:ctl_tipos_alimento,id'],
            'fecha' => ['required', 'date'],
            'hora' => ['nullable', 'string', 'max:8'],
            'cantidad' => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
            'unidad_medida' => ['nullable', 'string', 'max:20'],
            'costo_total' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_division.required' => 'La división o corral es obligatorio.',
            'id_division.exists' => 'La división seleccionada no existe.',
            'id_tipo_alimento.required' => 'El tipo de alimento es obligatorio.',
            'id_tipo_alimento.exists' => 'El tipo de alimento seleccionado no existe.',
            'fecha.required' => 'La fecha de suministro es obligatoria.',
            'fecha.date' => 'La fecha debe tener un formato válido.',
            'cantidad.required' => 'La cantidad suministrada es obligatoria.',
            'cantidad.numeric' => 'La cantidad debe ser un número válido.',
            'cantidad.min' => 'La cantidad debe ser mayor a cero.',
            'costo_total.numeric' => 'El costo debe ser un valor numérico.',
            'costo_total.min' => 'El costo no puede ser negativo.',
        ];
    }
}

