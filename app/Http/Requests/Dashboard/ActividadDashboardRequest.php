<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class ActividadDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            // Mismas opciones que ofrece PaginationBar en el frontend.
            'per_page' => ['nullable', 'integer', 'in:5,10,20'],
        ];
    }

    public function messages(): array
    {
        return [
            'page.integer' => 'La página debe ser numérica.',
            'page.min' => 'La página debe ser 1 o mayor.',
            'per_page.integer' => 'La cantidad por página debe ser numérica.',
            'per_page.in' => 'La cantidad por página debe ser 5, 10 o 20.',
        ];
    }
}
