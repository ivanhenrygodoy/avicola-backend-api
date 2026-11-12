<?php

namespace App\Http\Requests\Asistente;

use Illuminate\Foundation\Http\FormRequest;

class RenombrarConversacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Se recorta el nombre antes de validar: "   " cuenta como vacío y los espacios de los extremos no se guardan.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nombre'))) {
            $this->merge(['nombre' => trim($this->input('nombre'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Una sola línea: sin saltos de línea ni retornos de carro.
            'nombre' => ['required', 'string', 'min:1', 'max:60', 'regex:/^[^\r\n]+$/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la conversación es obligatorio.',
            'nombre.string' => 'El nombre de la conversación debe ser texto.',
            'nombre.min' => 'El nombre de la conversación es obligatorio.',
            'nombre.max' => 'El nombre de la conversación no puede exceder los 60 caracteres.',
            'nombre.regex' => 'El nombre de la conversación debe estar en una sola línea.',
        ];
    }
}
