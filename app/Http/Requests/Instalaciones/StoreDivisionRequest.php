<?php

namespace App\Http\Requests\Instalaciones;

use Illuminate\Foundation\Http\FormRequest;

class StoreDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_casita' => ['required', 'exists:mnt_casitas,id'],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'activa' => ['nullable', 'boolean'],
        ];
    }
}

