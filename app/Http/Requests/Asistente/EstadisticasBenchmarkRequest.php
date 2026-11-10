<?php

namespace App\Http\Requests\Asistente;

use Illuminate\Foundation\Http\FormRequest;

class EstadisticasBenchmarkRequest extends FormRequest
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
            'dias' => ['nullable', 'integer', 'in:7,30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dias.integer' => 'El rango de días debe ser un número.',
            'dias.in' => 'El rango de días debe ser 7 o 30.',
        ];
    }
}
