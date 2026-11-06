<?php

namespace App\Http\Requests\Aves;

use Illuminate\Foundation\Http\FormRequest;

class StoreObservacionAveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'tipo' => ['required', 'string', 'in:Salud,Comportamiento,Vacunación,Incidencia,Nutrición,General'],
            'titulo' => ['required', 'string', 'max:150'],
            'descripcion' => ['required', 'string'],
            'accion_tomada' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'fecha.required' => 'La fecha del registro es obligatoria.',
            'fecha.date' => 'La fecha no tiene un formato válido.',
            'tipo.required' => 'El tipo de observación es obligatorio.',
            'tipo.in' => 'El tipo de observación seleccionado no es válido.',
            'titulo.required' => 'El título o motivo es obligatorio.',
            'titulo.max' => 'El título no debe exceder 150 caracteres.',
            'descripcion.required' => 'La descripción del evento es obligatoria.',
        ];
    }
}

