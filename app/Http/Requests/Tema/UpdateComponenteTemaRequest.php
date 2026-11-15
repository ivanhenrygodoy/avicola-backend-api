<?php

namespace App\Http\Requests\Tema;

use App\Models\ConfiguracionTema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComponenteTemaRequest extends FormRequest
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
            'modo' => ['required', 'string', Rule::in([ConfiguracionTema::MODO_CLARO, ConfiguracionTema::MODO_NOCTURNO])],
            'aspectos' => ['required', 'array', 'min:1'],
            'aspectos.*.aspecto' => ['required', 'string', 'max:60'],
            'aspectos.*.valor_actual' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * Validación cruzada: los aspectos de tipo `color` deben ser hex `#RRGGBB`.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $componente = $this->route('componente');
            if (! $componente) {
                return;
            }

            $modo = $this->input('modo');
            $existentes = $componente->configuraciones()
                ->where('modo', $modo)
                ->get()
                ->keyBy('aspecto');

            foreach ($this->input('aspectos', []) as $index => $item) {
                $clave = $item['aspecto'] ?? null;
                $valor = trim((string) ($item['valor_actual'] ?? ''));

                if (! $clave || ! $existentes->has($clave)) {
                    $validator->errors()->add("aspectos.{$index}.aspecto", "El aspecto '{$clave}' no pertenece a este componente.");
                    continue;
                }

                $config = $existentes->get($clave);
                if ($config->tipo_control === ConfiguracionTema::CONTROL_COLOR) {
                    if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $valor)) {
                        $validator->errors()->add(
                            "aspectos.{$index}.valor_actual",
                            "El color para '{$config->etiqueta}' debe tener formato hexadecimal válido (#RRGGBB)."
                        );
                    }
                } elseif (str_contains($valor, ';') || str_contains($valor, '{') || str_contains($valor, '}')) {
                    $validator->errors()->add(
                        "aspectos.{$index}.valor_actual",
                        "El valor de '{$config->etiqueta}' contiene caracteres no permitidos."
                    );
                }
            }
        });
    }
}

