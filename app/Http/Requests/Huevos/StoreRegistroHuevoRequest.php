<?php

namespace App\Http\Requests\Huevos;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistroHuevoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_division' => ['required', 'integer', 'exists:mnt_divisiones,id'],
            'fecha' => ['required', 'date'],
            'cantidad_recolectada' => ['required', 'integer', 'min:0'],
            'cantidad_rotos' => ['nullable', 'integer', 'min:0', 'lte:cantidad_recolectada'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_division.required' => 'La división o corral es obligatorio.',
            'id_division.exists' => 'La división seleccionada no existe.',
            'fecha.required' => 'La fecha de recolección es obligatoria.',
            'fecha.date' => 'La fecha debe tener un formato válido.',
            'cantidad_recolectada.required' => 'La cantidad recolectada es obligatoria.',
            'cantidad_recolectada.integer' => 'La cantidad recolectada debe ser un número entero.',
            'cantidad_recolectada.min' => 'La cantidad recolectada no puede ser negativa.',
            'cantidad_rotos.integer' => 'La cantidad de huevos rotos debe ser un número entero.',
            'cantidad_rotos.min' => 'La cantidad de huevos rotos no puede ser negativa.',
            'cantidad_rotos.lte' => 'La cantidad de huevos rotos no puede ser superior a los huevos recolectados.',
        ];
    }
}

