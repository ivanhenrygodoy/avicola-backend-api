<?php

namespace App\Http\Requests\Asistente;

use App\Services\Asistente\Benchmark\DepuracionLogsAgenteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepuracionLogsAgenteRequest extends FormRequest
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
            'meses' => ['required', 'integer', Rule::in(DepuracionLogsAgenteService::MESES_PERMITIDOS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meses.required' => 'El período en meses es obligatorio.',
            'meses.integer' => 'El período en meses debe ser un número entero.',
            'meses.in' => 'El período en meses debe ser 3, 6 o 12 meses.',
        ];
    }
}
