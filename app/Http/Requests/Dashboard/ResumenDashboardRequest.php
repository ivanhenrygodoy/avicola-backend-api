<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class ResumenDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dias' => ['nullable', 'integer', 'in:7,30'],
        ];
    }

    public function messages(): array
    {
        return [
            'dias.integer' => 'El rango de días debe ser numérico.',
            'dias.in' => 'El rango de días debe ser 7 o 30.',
        ];
    }
}
