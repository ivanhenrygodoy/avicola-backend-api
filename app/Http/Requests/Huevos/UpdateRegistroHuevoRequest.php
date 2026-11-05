<?php

namespace App\Http\Requests\Huevos;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegistroHuevoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Si no se envía cantidad_recolectada en el update, tomar el valor existente del modelo en route
        $registro = $this->route('huevo');
        $recolectada = $this->input('cantidad_recolectada', $registro?->cantidad_recolectada ?? 0);

        return [
            'id_division' => ['sometimes', 'required', 'integer', 'exists:mnt_divisiones,id'],
            'fecha' => ['sometimes', 'required', 'date'],
            'cantidad_recolectada' => ['sometimes', 'required', 'integer', 'min:0'],
            'cantidad_rotos' => ['nullable', 'integer', 'min:0', 'lte:' . $recolectada],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_division.exists' => 'La división seleccionada no existe.',
            'fecha.date' => 'La fecha debe tener un formato válido.',
            'cantidad_recolectada.integer' => 'La cantidad recolectada debe ser un número entero.',
            'cantidad_recolectada.min' => 'La cantidad recolectada no puede ser negativa.',
            'cantidad_rotos.integer' => 'La cantidad de huevos rotos debe ser un número entero.',
            'cantidad_rotos.min' => 'La cantidad de huevos rotos no puede ser negativa.',
            'cantidad_rotos.lte' => 'La cantidad de huevos rotos no puede ser superior a los huevos recolectados.',
        ];
    }
}

