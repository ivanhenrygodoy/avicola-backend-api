<?php

namespace App\Http\Requests\Instalaciones;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCasitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'tipo' => ['nullable', 'string', 'max:50'],
            'activa' => ['sometimes', 'boolean'],
        ];
    }
}

