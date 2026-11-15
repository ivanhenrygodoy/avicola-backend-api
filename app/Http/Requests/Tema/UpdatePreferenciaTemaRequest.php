<?php

namespace App\Http\Requests\Tema;

use App\Models\ConfiguracionTema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferenciaTemaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modo_tema' => ['required', 'string', Rule::in([ConfiguracionTema::MODO_CLARO, ConfiguracionTema::MODO_NOCTURNO])],
        ];
    }
}

