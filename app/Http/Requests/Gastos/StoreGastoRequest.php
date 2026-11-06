<?php

namespace App\Http\Requests\Gastos;

use Illuminate\Foundation\Http\FormRequest;

class StoreGastoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_categoria_gasto' => ['required', 'integer', 'exists:ctl_categorias_gastos,id'],
            'id_division' => ['nullable', 'integer', 'exists:mnt_divisiones,id'],
            'fecha' => ['required', 'date'],
            'monto' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'concepto' => ['required', 'string', 'max:255'],
            'proveedor' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_categoria_gasto.required' => 'La categoría del gasto es obligatoria.',
            'id_categoria_gasto.exists' => 'La categoría seleccionada no existe.',
            'id_division.exists' => 'La división o corral seleccionado no existe.',
            'fecha.required' => 'La fecha del gasto es obligatoria.',
            'fecha.date' => 'La fecha ingresada no tiene un formato válido.',
            'monto.required' => 'El monto del gasto es obligatorio.',
            'monto.numeric' => 'El monto debe ser un valor numérico.',
            'monto.min' => 'El monto debe ser mayor a 0.',
            'monto.max' => 'El monto excede el límite permitido.',
            'concepto.required' => 'El concepto o descripción corta del gasto es obligatorio.',
            'concepto.max' => 'El concepto no debe superar 255 caracteres.',
            'proveedor.max' => 'El proveedor no debe superar 150 caracteres.',
            'observaciones.max' => 'Las observaciones no deben superar 1000 caracteres.',
        ];
    }
}

