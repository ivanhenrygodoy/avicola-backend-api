<?php

namespace App\Http\Requests\Asistente;

use App\Models\FuncionLogAgent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FiltroLogsAgenteRequest extends FormRequest
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
            'resultado' => ['nullable', 'string', Rule::in([
                FuncionLogAgent::RESULTADO_EXITOSO,
                FuncionLogAgent::RESULTADO_FAST_PATH,
                FuncionLogAgent::RESULTADO_BLOQUEADO,
                FuncionLogAgent::RESULTADO_ERROR,
            ])],
            'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'guard' => ['nullable', 'string', 'max:100'],
            'herramienta' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resultado.in' => 'El resultado no es válido.',
            'fecha_inicio.date_format' => 'La fecha de inicio debe tener el formato AAAA-MM-DD.',
            'fecha_fin.date_format' => 'La fecha de fin debe tener el formato AAAA-MM-DD.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior a la fecha de inicio.',
            'per_page.max' => 'No se pueden pedir más de 100 registros por página.',
        ];
    }
}
