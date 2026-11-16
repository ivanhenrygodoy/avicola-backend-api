<?php

namespace App\Services\Asistente\Rag;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use RuntimeException;

class ExtraccionTextoService
{
    /**
     * Extrae el texto normalizado a partir de un archivo subido o ruta en disco.
     */
    public function extraerDeArchivo(UploadedFile|string $archivo): string
    {
        $ruta = $archivo instanceof UploadedFile ? $archivo->getRealPath() : $archivo;
        $extension = strtolower(pathinfo($archivo instanceof UploadedFile ? $archivo->getClientOriginalName() : $archivo, PATHINFO_EXTENSION));

        if (!file_exists($ruta)) {
            throw new RuntimeException("El archivo a procesar no existe en la ruta especificada: {$ruta}");
        }

        switch ($extension) {
            case 'pdf':
                return $this->extraerDePdf($ruta);
            case 'txt':
            case 'md':
            case 'markdown':
                return $this->extraerDeTextoPlano($ruta);
            default:
                // Intentar leer como texto si no tiene extensión reconocida
                return $this->extraerDeTextoPlano($ruta);
        }
    }

    /**
     * Extrae texto de un archivo PDF usando Smalot PdfParser.
     */
    public function extraerDePdf(string $ruta): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($ruta);
            $texto = $pdf->getText();

            return $this->normalizarTexto($texto);
        } catch (Throwable $e) {
            throw new RuntimeException("Error al extraer texto del PDF: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extrae texto de archivos .txt o .md.
     */
    public function extraerDeTextoPlano(string $ruta): string
    {
        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException("No se pudo leer el archivo de texto en {$ruta}");
        }

        return $this->normalizarTexto($contenido);
    }

    /**
     * Sanitiza y normaliza un texto plano directo (sin caracteres nulos, unificando saltos de línea).
     */
    public function normalizarTexto(string $texto): string
    {
        // Convertir a UTF-8 válido si viene con codificación irregular
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'auto');
        }

        // Remover caracteres de control nulos o corruptos
        $texto = str_replace(["\0", "\x0B"], '', $texto);

        // Unificar saltos de línea a \n
        $texto = preg_replace("/\r\n|\r/", "\n", $texto);

        // Limpiar espacios en blanco excesivos y tabs
        $texto = preg_replace("/[ \t]+/", ' ', $texto);

        // Limpiar más de 2 saltos de línea consecutivos
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);

        return trim($texto);
    }
}

