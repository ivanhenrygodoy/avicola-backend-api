<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Gemini API Configuration
    |--------------------------------------------------------------------------
    |
    | Clave de API, modelo por defecto y endpoints para el Asistente Virtual
    | de la Granja Avícola con Function Calling.
    |
    */

    'api_key' => env('GEMINI_API_KEY', ''),

    'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),

    'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),

    'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 768),

    'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

    'timeout' => (int) env('GEMINI_TIMEOUT', 45),
];
