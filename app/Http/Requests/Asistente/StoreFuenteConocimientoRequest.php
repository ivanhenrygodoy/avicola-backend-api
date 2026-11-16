<?php

namespace App\Http\Requests\Asistente;

use Illuminate\Foundation\Http\FormRequest;

class StoreFuenteConocimientoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo_fuente' => ['required', 'string', 'in:documento,texto'],
            'titulo' => ['required_if:accion,procesar', 'nullable', 'string', 'max:150'],
            'especie' => ['required_if:accion,procesar', 'nullable', 'string', 'max:50'],
            'descripcion' => ['required_if:accion,procesar', 'nullable', 'string', 'max:1000'],
            'autor' => ['required_if:accion,procesar', 'nullable', 'string', 'max:100'],
            'version' => ['required_if:accion,procesar', 'nullable', 'string', 'max:20'],
            'archivo' => [
                'required_if:tipo_fuente,documento',
                'nullable',
                'file',
                'mimes:pdf,txt,md',
                'max:20480', // Máximo 20 MB
            ],
            'texto_contenido' => [
                'required_if:tipo_fuente,texto',
                'nullable',
                'string',
                'min:10',
            ],
            'accion' => ['nullable', 'string', 'in:borrador,procesar'],
        ];
    }

    /**
     * Mensajes descriptivos en español para errores de validación.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_fuente.required' => 'El tipo de fuente es obligatorio (documento o texto).',
            'tipo_fuente.in' => 'El tipo de fuente debe ser "documento" o "texto".',
            'titulo.required_if' => 'El título de la fuente de conocimiento es obligatorio para procesar.',
            'titulo.max' => 'El título no puede exceder los 150 caracteres.',
            'especie.required_if' => 'Debe seleccionar la especie destino para procesar.',
            'descripcion.required_if' => 'La descripción o resumen es obligatoria para procesar.',
            'autor.required_if' => 'El autor o entidad es obligatorio para procesar.',
            'version.required_if' => 'La versión del documento es obligatoria para procesar.',
            'archivo.required_if' => 'Debe adjuntar un archivo cuando el tipo de fuente es "documento".',
            'archivo.mimes' => 'El archivo debe ser de formato PDF, TXT o Markdown (.md).',
            'archivo.max' => 'El archivo no puede superar los 20 megabytes.',
            'texto_contenido.required_if' => 'Debe ingresar el contenido de texto cuando el tipo de fuente es "texto directo".',
            'texto_contenido.min' => 'El texto directo debe contener al menos 10 caracteres.',
            'accion.in' => 'La acción debe ser "borrador" o "procesar".',
        ];
    }
}

