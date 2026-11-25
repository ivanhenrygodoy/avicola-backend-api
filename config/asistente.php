<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Conversaciones del asistente AviaCore
    |--------------------------------------------------------------------------
    |
    | Valores por defecto de la persistencia y el historial de conversaciones. Cada uno se puede
    | ajustar por variable de entorno; un valor no numérico, cero o negativo se ignora y se usa el
    | valor por defecto (ver ConversacionAsistenteService::entero).
    |
    */
    'conversacion' => [
        // Mensajes que se reenvían al modelo como contexto (los últimos, solo de turnos exitosos).
        'contexto_mensajes' => env('ASISTENTE_CONTEXTO_MENSAJES', 6),

        // Conversaciones que devuelve el listado del historial (las más recientes).
        'listado_max' => env('ASISTENTE_LISTADO_MAX', 50),

        // Mensajes que se cargan al abrir una conversación (los últimos).
        'mensajes_max_carga' => env('ASISTENTE_MENSAJES_MAX_CARGA', 100),

        // Largo máximo del nombre de una conversación.
        'nombre_max' => env('ASISTENTE_NOMBRE_MAX', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Búsqueda híbrida RAG (Vectorial + Léxica con RRF)
    |--------------------------------------------------------------------------
    |
    | Parámetros de fusión Reciprocal Rank Fusion (RRF) para combinar similitud
    | semántica (pgvector) con coincidencia léxica (tsvector en PostgreSQL).
    |
    */
    'rag' => [
        // Constante de suavizado k en la fórmula RRF: peso / (k + rank).
        'rrf_k' => (int) env('ASISTENTE_RAG_RRF_K', 60),

        // Cantidad de candidatos que recupera cada rama antes de fusionar.
        'candidatos_por_rama' => (int) env('ASISTENTE_RAG_CANDIDATOS', 15),

        // Pesos relativos de cada rama en el puntaje RRF final.
        'peso_vectorial' => (float) env('ASISTENTE_RAG_PESO_VECTORIAL', 1.0),
        'peso_lexico' => (float) env('ASISTENTE_RAG_PESO_LEXICO', 1.0),
    ],
];
