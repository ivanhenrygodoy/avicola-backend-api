<?php

namespace Database\Seeders;

use App\Services\Asistente\Rag\IngestaManualesService;
use Illuminate\Database\Seeder;

class DocumentosTecnicosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(IngestaManualesService $ingestaService): void
    {
        $apiKey = config('gemini.api_key');
        $simular = empty($apiKey) || app()->environment('testing');

        $resultado = $ingestaService->ingestarManuales(simularEmbeddings: $simular);

        $this->command?->info("Ingesta completada: {$resultado['documentos_creados']} documentos y {$resultado['fragmentos_creados']} fragmentos técnicos vectorizados.");
    }
}
