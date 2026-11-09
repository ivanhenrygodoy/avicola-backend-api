<?php

namespace App\Http\Requests\Asistente;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
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
            'mensaje' => ['required', 'string', 'min:1', 'max:1000'],
            'historial' => ['nullable', 'array', 'max:20'],
            'historial.*.role' => ['required_with:historial', 'string', 'in:user,model'],
            'historial.*.parts' => ['required_with:historial', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mensaje.required' => 'El mensaje es obligatorio.',
            'mensaje.string' => 'El mensaje debe ser una cadena de texto.',
            'mensaje.min' => 'El mensaje debe contener al menos 1 caracter.',
            'mensaje.max' => 'El mensaje no puede exceder los 1000 caracteres.',
            'historial.array' => 'El historial debe ser un arreglo.',
            'historial.max' => 'El historial no puede superar los 20 mensajes.',
        ];
    }
}

