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
];
