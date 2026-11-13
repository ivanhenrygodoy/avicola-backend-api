<?php

namespace App\Http\Requests\Acceso;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsuarioRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:mnt_usuarios,email'],
            'id_rol' => ['required', 'integer', 'exists:mnt_roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ya existe un usuario con ese correo.',
            'id_rol.required' => 'Selecciona un rol.',
            'id_rol.exists' => 'El rol seleccionado no existe.',
        ];
    }
}
