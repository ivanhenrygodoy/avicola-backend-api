<?php

namespace App\Http\Requests\Aves;

use Illuminate\Foundation\Http\FormRequest;

class TrasladarAveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'id_division_destino' => ['required', 'integer', 'exists:mnt_divisiones,id'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string'],
            'fecha' => ['nullable', 'date'],
        ];
    }

    /**
     * Custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'id_division_destino.required' => 'La división de destino es obligatoria.',
            'id_division_destino.exists' => 'La división de destino seleccionada no existe.',
        ];
    }
}

