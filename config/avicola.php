<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zona horaria de negocio
    |--------------------------------------------------------------------------
    |
    | Define qué significa "hoy" y "este mes" para la granja. La aplicación
    | guarda timestamps en UTC, pero las fechas operativas (postura, raciones,
    | gastos) se interpretan en esta zona.
    |
    */

    'timezone' => env('AVICOLA_TIMEZONE', 'America/El_Salvador'),

];
