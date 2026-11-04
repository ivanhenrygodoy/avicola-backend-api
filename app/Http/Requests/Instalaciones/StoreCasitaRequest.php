<?php

namespace App\Http\Requests\Instalaciones;

use Illuminate\Foundation\Http\FormRequest;

class StoreCasitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_granja' => ['required', 'exists:mnt_granjas,id'],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'tipo' => ['nullable', 'string', 'max:50'],
            'activa' => ['nullable', 'boolean'],
        ];
    }
}

