<?php

namespace App\Services\Asistente\Rag;

class ChunkingService
{
    private const MAX_PALABRAS_POR_CHUNK = 500;
    private const MIN_PALABRAS_POR_CHUNK = 40;
    private const SOLAPAMIENTO_PALABRAS = 50;

    /**
     * Trocea el texto en una lista de fragmentos con título de sección y contenido.
     *
     * @return array<int, array{titulo_seccion: string, contenido: string, metadata: array<string, mixed>}>
     */
    public function trocear(string $texto, string $tituloDocumento = 'Documento'): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        // 1. Intentar dividir por encabezados markdown o palabras clave de capítulo
        $seccionesEncabezados = $this->dividirPorEncabezados($texto);

        if (!empty($seccionesEncabezados)) {
            $chunks = [];
            $orden = 1;

            foreach ($seccionesEncabezados as $sec) {
                $subChunks = $this->dividirPorVentanaPalabras(
                    $sec['contenido'],
                    $sec['titulo'],
                    self::MAX_PALABRAS_POR_CHUNK,
                    self::SOLAPAMIENTO_PALABRAS
                );

                foreach ($subChunks as $chunk) {
                    $chunk['metadata']['orden'] = $orden++;
                    $chunks[] = $chunk;
                }
            }

            return $chunks;
        }

        // 2. Si no hay encabezados claros, dividir por párrafos / ventanas de palabras
        return $this->dividirPorVentanaPalabras(
            $texto,
            $tituloDocumento,
            self::MAX_PALABRAS_POR_CHUNK,
            self::SOLAPAMIENTO_PALABRAS
        );
    }

    /**
     * Detecta encabezados (como # Titulo, ## Seccion, Capítulo X, Sección X).
     *
     * @return array<int, array{titulo: string, contenido: string}>
     */
    private function dividirPorEncabezados(string $texto): array
    {
        $lineas = explode("\n", $texto);
        $secciones = [];
        $tituloActual = null;
        $contenidoActual = [];

        foreach ($lineas as $linea) {
            $trim = trim($linea);

            // Detectar # Titulo, ## Subtitulo, o Capítulo 1:, Sección 1:
            $esEncabezado = preg_match('/^(#{1,4}\s+|cap[ií]tulo\s+\d+|secci[oó]n\s+\d+)/i', $trim);

            if ($esEncabezado) {
                if ($tituloActual !== null && !empty($contenidoActual)) {
                    $cuerpo = trim(implode("\n", $contenidoActual));
                    if ($cuerpo !== '') {
                        $secciones[] = [
                            'titulo' => $tituloActual,
                            'contenido' => $cuerpo,
                        ];
                    }
                }

                $tituloActual = preg_replace('/^#{1,4}\s+/', '', $trim);
                $contenidoActual = [];
            } else {
                $contenidoActual[] = $linea;
            }
        }

        if ($tituloActual !== null && !empty($contenidoActual)) {
            $cuerpo = trim(implode("\n", $contenidoActual));
            if ($cuerpo !== '') {
                $secciones[] = [
                    'titulo' => $tituloActual,
                    'contenido' => $cuerpo,
                ];
            }
        }

        return $secciones;
    }

    /**
     * Divide un cuerpo de texto en fragmentos con longitud controlada y solapamiento.
     *
     * @return array<int, array{titulo_seccion: string, contenido: string, metadata: array<string, mixed>}>
     */
    private function dividirPorVentanaPalabras(
        string $texto,
        string $tituloSeccion,
        int $tamanoVentana = self::MAX_PALABRAS_POR_CHUNK,
        int $solapamiento = self::SOLAPAMIENTO_PALABRAS
    ): array {
        $palabras = preg_split('/\s+/', trim($texto));
        $totalPalabras = count($palabras);

        if ($totalPalabras <= $tamanoVentana) {
            return [
                [
                    'titulo_seccion' => $tituloSeccion,
                    'contenido' => trim($texto),
                    'metadata' => [
                        'palabras' => $totalPalabras,
                        'caracteres' => mb_strlen($texto),
                    ],
                ],
            ];
        }

        $chunks = [];
        $inicio = 0;
        $parte = 1;

        while ($inicio < $totalPalabras) {
            $fin = min($inicio + $tamanoVentana, $totalPalabras);
            $segmentoPalabras = array_slice($palabras, $inicio, $fin - $inicio);
            $contenidoChunk = implode(' ', $segmentoPalabras);

            $chunks[] = [
                'titulo_seccion' => $totalPalabras > $tamanoVentana ? "{$tituloSeccion} (Parte {$parte})" : $tituloSeccion,
                'contenido' => trim($contenidoChunk),
                'metadata' => [
                    'parte' => $parte,
                    'palabras' => count($segmentoPalabras),
                    'caracteres' => mb_strlen($contenidoChunk),
                ],
            ];

            $parte++;
            $inicio += ($tamanoVentana - $solapamiento);

            if ($inicio >= $totalPalabras) {
                break;
            }
        }

        return $chunks;
    }
}

