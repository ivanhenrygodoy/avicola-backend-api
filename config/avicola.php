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

    /*
    |--------------------------------------------------------------------------
    | Credenciales por defecto para el administrador inicial (Seeder)
    |--------------------------------------------------------------------------
    */
    'admin_default_email' => env('ADMIN_DEFAULT_EMAIL', 'admin@granja.local'),
    'admin_default_password' => env('ADMIN_DEFAULT_PASSWORD'),
    'admin_default_name' => env('ADMIN_DEFAULT_NAME', 'Administrador de Granja'),

];
