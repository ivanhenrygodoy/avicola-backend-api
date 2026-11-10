<?php

namespace App\Services\Asistente\Contracts;

/**
 * Contrato formal para herramientas ejecutables por el Asistente de IA (Function Calling).
 */
interface AsistenteToolInterface
{
    /**
     * Nombre único de la función (formato snake_case para Gemini).
     */
    public function nombre(): string;

    /**
     * Descripción concisa y clara de qué hace la herramienta y cuándo usarla.
     */
    public function descripcion(): string;

    /**
     * Esquema OpenAPI de parámetros esperados por la función.
     */
    public function esquema(): array;

    /**
     * Tablas de base de datos que la herramienta lee al ejecutarse (se registra en la auditoría del Benchmark).
     *
     * @return array<int, string>
     */
    public function tablasConsultadas(): array;

    /**
     * Ejecuta la lógica de consulta y retorna un array asociativo con datos compactos.
     *
     * @param  array<string, mixed>  $argumentos  Argumentos generados por el LLM.
     * @param  array<string, mixed>  $contextoFijo  Parámetros de sesión verificados (id_usuario, id_granja).
     * @return array<string, mixed>
     */
    public function ejecutar(array $argumentos, array $contextoFijo = []): array;
}
