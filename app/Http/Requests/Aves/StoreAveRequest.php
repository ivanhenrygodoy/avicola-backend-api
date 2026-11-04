<?php

namespace App\Http\Requests\Aves;

use Illuminate\Foundation\Http\FormRequest;

class StoreAveRequest extends FormRequest
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
            'codigo' => ['required', 'string', 'max:50', 'unique:mnt_aves,codigo'],
            'nombre' => ['nullable', 'string', 'max:100'],
            'id_especie' => ['required', 'integer', 'exists:ctl_especie,id'],
            'id_sexo' => ['required', 'integer', 'exists:ctl_sexo,id'],
            'raza' => ['nullable', 'string', 'max:100'],
            'proposito' => ['nullable', 'string', 'max:100'],
            'id_division' => ['nullable', 'integer', 'exists:mnt_divisiones,id'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'fecha_ingreso' => ['nullable', 'date'],
            'estado' => ['nullable', 'string', 'in:activa,trasladada,fallecida,salida'],
            'peso_actual_kg' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'observaciones' => ['nullable', 'string'],
        ];
    }

    /**
     * Custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'El código del ave es obligatorio.',
            'codigo.unique' => 'Ya existe un ave registrada con este código.',
            'id_especie.required' => 'La especie es obligatoria.',
            'id_especie.exists' => 'La especie seleccionada no es válida.',
            'id_sexo.required' => 'El sexo es obligatorio.',
            'id_sexo.exists' => 'El sexo seleccionado no es válido.',
            'id_division.exists' => 'La división seleccionada no existe.',
            'peso_actual_kg.numeric' => 'El peso debe ser un valor numérico.',
            'peso_actual_kg.min' => 'El peso no puede ser negativo.',
        ];
    }
}

