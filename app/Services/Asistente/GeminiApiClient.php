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
        $this->timeout = $timeout ?? (int) config('gemini.timeout', 45);
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

        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 500, throw: false)
                ->post($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException("Tiempo de espera agotado al conectar con el servicio de IA de Gemini ({$this->timeout}s).", previous: $e);
        } catch (\Throwable $e) {
            throw new RuntimeException('Error de comunicación con el servicio de IA de Gemini: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Error en el servicio de IA de Gemini ({$response->status()}): {$response->body()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Genera el vector de embedding numérico (768 dimensiones) para un texto dado.
     *
     * @return array<int, float>
     */
    public function generarEmbedding(string $texto, ?string $embeddingModel = null): array
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('La clave de API de Gemini no está configurada.');
        }

        $model = $embeddingModel ?? (string) config('gemini.embedding_model', 'gemini-embedding-001');
        $dimensions = (int) config('gemini.embedding_dimensions', 768);
        $url = rtrim($this->baseUrl, '/')."/models/{$model}:embedContent?key={$this->apiKey}";

        $payload = [
            'model' => "models/{$model}",
            'content' => [
                'parts' => [
                    ['text' => $texto],
                ],
            ],
            'outputDimensionality' => $dimensions,
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->retry(2, 500, throw: false)
                ->post($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException("Tiempo de espera agotado al generar embedding en Gemini ({$this->timeout}s).", previous: $e);
        } catch (\Throwable $e) {
            throw new RuntimeException('Error de comunicación al generar embedding en Gemini: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Error al generar embedding en Gemini ({$response->status()}): {$response->body()}");
        }

        return $response->json('embedding.values') ?? [];
    }
}
