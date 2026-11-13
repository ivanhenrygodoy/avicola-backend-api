<?php

namespace App\Http\Requests\Acceso;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso lo decide el middleware `modulo:usuarios` de la ruta.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'id_rol' => ['required_without:activo', 'integer', 'exists:mnt_roles,id'],
            'activo' => ['required_without:id_rol', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_rol.required_without' => 'Indica el rol o el estado que quieres cambiar.',
            'activo.required_without' => 'Indica el rol o el estado que quieres cambiar.',
            'id_rol.exists' => 'El rol seleccionado no existe.',
            'activo.boolean' => 'El estado no es válido.',
        ];
    }
}
