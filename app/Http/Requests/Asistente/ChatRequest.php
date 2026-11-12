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
            // El historial ya no lo manda el navegador: el servidor arma el contexto desde la conversación
            // guardada, así que un `historial` enviado por el cliente simplemente se ignora (no se valida).
            // El UUID es solo una pista de qué conversación retomar; si no es válido o no es del usuario se
            // ignora y se crea otra, por eso no se rechaza con 422.
            'uuid_conversacion' => ['nullable', 'string', 'max:64'],
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
        ];
    }
}
