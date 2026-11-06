<?php

namespace App\Http\Requests\Aves;

use Illuminate\Foundation\Http\FormRequest;

class StoreRegistroPesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'peso_kg' => ['required', 'numeric', 'min:0.01', 'max:999.99'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'La fecha del pesaje es obligatoria.',
            'fecha.date' => 'La fecha del pesaje no tiene un formato válido.',
            'peso_kg.required' => 'El peso en kilogramos es obligatorio.',
            'peso_kg.numeric' => 'El peso debe ser un valor numérico.',
            'peso_kg.min' => 'El peso debe ser mayor a 0 kg.',
            'peso_kg.max' => 'El peso ingresado supera el límite permitido.',
        ];
    }
}

