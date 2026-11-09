<?php

namespace App\Console\Commands;

use App\Services\Asistente\Rag\IngestaManualesService;
use Illuminate\Console\Command;

class IngestarManualesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'avicola:ingestar-manuales {--fake : Simular embeddings sin llamar a la API externa de Gemini}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ingesta los manuales técnicos zootécnicos y genera sus fragmentos vectoriales (pgvector)';

    /**
     * Execute the console command.
     */
    public function handle(IngestaManualesService $ingestaService): int
    {
        $fake = (bool) $this->option('fake');
        $this->info($fake ? 'Iniciando ingesta con vectores simulados...' : 'Iniciando ingesta generando embeddings con Gemini text-embedding-004...');

        $resultado = $ingestaService->ingestarManuales(simularEmbeddings: $fake);

        $this->info("Ingesta finalizada exitosamente: {$resultado['documentos_creados']} documentos y {$resultado['fragmentos_creados']} fragmentos almacenados.");

        return self::SUCCESS;
    }
}
