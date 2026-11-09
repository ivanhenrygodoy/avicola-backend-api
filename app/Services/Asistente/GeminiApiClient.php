<?php

namespace App\Services\Asistente;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP desacoplado para la API de Google Gemini (generateContent).
 */
class GeminiApiClient
{
    private string $apiKey;

    private string $model;

    private string $baseUrl;

    private int $timeout;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
        ?int $timeout = null
    ) {
        $this->apiKey = $apiKey ?? (string) config('gemini.api_key', '');
        $this->model = $model ?? (string) config('gemini.model', 'gemini-1.5-flash');
        $this->baseUrl = $baseUrl ?? (string) config('gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        $this->timeout = $timeout ?? (int) config('gemini.timeout', 30);
    }

    /**
     * Envía una petición de generación de contenido a Google Gemini.
     *
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<string, mixed>
     */
    public function generarContenido(array $contents, array $tools = [], ?string $systemInstruction = null): array
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('La clave de API de Gemini no está configurada.');
        }

        $payload = [
            'contents' => $contents,
        ];

        if ($systemInstruction !== null && trim($systemInstruction) !== '') {
            $payload['system_instruction'] = [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ];
        }

        if (! empty($tools)) {
            $payload['tools'] = [
                ['function_declarations' => $tools],
            ];
        }

        $url = rtrim($this->baseUrl, '/')."/models/{$this->model}:generateContent?key={$this->apiKey}";

        $response = Http::timeout($this->timeout)->post($url, $payload);

        if ($response->failed()) {
            throw new RuntimeException("Error en el servicio de IA de Gemini ({$response->status()}): {$response->body()}");
        }

        return $response->json() ?? [];
    }
}

