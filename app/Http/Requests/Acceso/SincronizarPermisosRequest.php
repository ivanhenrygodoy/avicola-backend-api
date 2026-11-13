<?php

namespace App\Http\Requests\Acceso;

use Illuminate\Foundation\Http\FormRequest;

class SincronizarPermisosRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso lo decide el middleware `modulo:permisos` de la ruta.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'modulos' => ['present', 'array'],
            'modulos.*' => ['string', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'modulos.present' => 'Debes enviar la lista de módulos.',
            'modulos.array' => 'La lista de módulos no es válida.',
        ];
    }
}
