<?php

namespace App\Services\Asistente;

/**
 * Resuelve sugerencias de navegación a módulos del sistema según el contexto y herramientas invocadas,
 * y sanea cualquier fuga involuntaria de nombres técnicos de tools en el texto redactado por el LLM.
 */
class ModuloNavigationResolver
{
    /**
     * Mapeo de módulos disponibles en el frontend.
     */
    public const MODULOS = [
        'produccion' => [
            'modulo' => 'produccion',
            'titulo' => 'Ir al Módulo de Producción',
            'ruta' => '/produccion',
            'descripcion' => 'Revisar registro de postura y alimentación diaria',
            'tools' => ['consultar_produccion_huevos', 'consultar_raciones_alimento'],
            'terminos' => [
                'produccion', 'producción', 'postura', 'huevo', 'huevos',
                'nidal', 'nidales', 'recolección', 'alimento', 'alimentos',
                'alimentación', 'ración', 'raciones', 'ciclo productivo',
                'tasa de postura', 'calidad del huevo',
            ],
        ],
        'aves' => [
            'modulo' => 'aves',
            'titulo' => 'Ir al Censo de Aves',
            'ruta' => '/aves',
            'descripcion' => 'Consultar inventario, traslados, pesajes y censo',
            'tools' => ['consultar_inventario_aves', 'consultar_ubicacion_ave'],
            'terminos' => [
                'inventario', 'censo', 'lote de aves', 'traslado', 'traslados',
                'pesaje', 'pesajes', 'peso de ave', 'anillo', 'código de ave',
                'fichas de aves', 'población de aves',
            ],
        ],
        'instalaciones' => [
            'modulo' => 'instalaciones',
            'titulo' => 'Ir a Instalaciones y Corrales',
            'ruta' => '/instalaciones',
            'descripcion' => 'Gestionar casitas, divisiones y capacidades',
            'tools' => [],
            'terminos' => [
                'casita', 'casitas', 'división', 'divisiones', 'corral',
                'corrales', 'capacidad de alojamiento', 'espacio por ave',
            ],
        ],
        'gastos' => [
            'modulo' => 'gastos',
            'titulo' => 'Ir al Control de Gastos',
            'ruta' => '/gastos',
            'descripcion' => 'Consultar egresos, compras de insumos y finanzas',
            'tools' => ['consultar_gastos_periodo'],
            'terminos' => [
                'gasto', 'gastos', 'finanzas', 'costo', 'costos',
                'egreso', 'egresos', 'presupuesto', 'compras de alimento',
            ],
        ],
        'dashboard' => [
            'modulo' => 'dashboard',
            'titulo' => 'Ir al Dashboard Principal',
            'ruta' => '/',
            'descripcion' => 'Ver indicadores clave de rendimiento (KPIs)',
            'tools' => [],
            'terminos' => [
                'dashboard', 'kpi', 'resumen general', 'métricas de la granja',
            ],
        ],
    ];

    /**
     * Resuelve los módulos sugeridos a partir de las tools ejecutadas y del texto de respuesta.
     *
     * @param  array<int, string>  $herramientasUtilizadas
     * @return array<int, array{modulo: string, titulo: string, ruta: string, descripcion: string}>
     */
    public function resolver(string $respuesta, array $herramientasUtilizadas = []): array
    {
        $sugerencias = [];
        $textoMin = mb_strtolower($respuesta);

        // 1. Detección por herramientas ejecutadas
        foreach (self::MODULOS as $clave => $config) {
            foreach ($config['tools'] as $tool) {
                if (in_array($tool, $herramientasUtilizadas, true)) {
                    $sugerencias[$clave] = [
                        'modulo' => $config['modulo'],
                        'titulo' => $config['titulo'],
                        'ruta' => $config['ruta'],
                        'descripcion' => $config['descripcion'],
                    ];
                    break;
                }
            }
        }

        // 2. Detección por mención EXPRESA y contextual de acudir al módulo en la respuesta
        // Solo cuando el asistente expresamente invita a visitar, revisar o registrar en el módulo
        $patronesAccion = [
            'produccion' => [
                '/(?:m[oó]dulo\s+de\s+producci[oó]n|secci[oó]n\s+de\s+producci[oó]n)/iu',
                '/(?:revisar|gestionar|analizar|verificar|registrar|dar\s+seguimiento)\s+(?:en\s+el\s+m[oó]dulo|la\s+postura|la\s+producci[oó]n|el\s+ciclo\s+productivo)/iu',
            ],
            'aves' => [
                '/(?:m[oó]dulo\s+de\s+aves|censo\s+de\s+aves|secci[oó]n\s+de\s+aves)/iu',
                '/(?:revisar|gestionar|verificar|registrar|dar\s+seguimiento)\s+(?:en\s+el\s+m[oó]dulo|el\s+censo|el\s+inventario\s+de\s+aves|las\s+fichas\s+de\s+aves)/iu',
            ],
            'instalaciones' => [
                '/(?:m[oó]dulo\s+de\s+instalaciones|secci[oó]n\s+de\s+instalaciones)/iu',
                '/(?:revisar|gestionar|verificar|configurar)\s+(?:las\s+casitas|las\s+divisiones|los\s+corrales)/iu',
            ],
            'gastos' => [
                '/(?:m[oó]dulo\s+de\s+gastos|control\s+de\s+gastos|secci[oó]n\s+de\s+finanzas)/iu',
                '/(?:revisar|gestionar|verificar|registrar)\s+(?:los\s+gastos|las\s+finanzas|los\s+costos)/iu',
            ],
            'dashboard' => [
                '/(?:panel\s+principal|dashboard\s+principal|pantalla\s+de\s+inicio)/iu',
            ],
        ];

        foreach ($patronesAccion as $clave => $patrones) {
            if (isset($sugerencias[$clave])) {
                continue;
            }

            foreach ($patrones as $patron) {
                if (preg_match($patron, $textoMin) === 1) {
                    $config = self::MODULOS[$clave];
                    $sugerencias[$clave] = [
                        'modulo' => $config['modulo'],
                        'titulo' => $config['titulo'],
                        'ruta' => $config['ruta'],
                        'descripcion' => $config['descripcion'],
                    ];
                    break;
                }
            }
        }

        // Limitar a máximo 2 sugerencias más relevantes para no saturar la UI
        return array_values(array_slice($sugerencias, 0, 2));
    }

    /**
     * Sanea el texto de la respuesta eliminando cualquier mención inadvertida a nombres de tools.
     */
    public function sanitizarRespuesta(string $texto): string
    {
        $reemplazos = [
            '/`?consultar_produccion_huevos`?/i' => 'el Módulo de Producción',
            '/`?consultar_inventario_aves`?/i' => 'el Módulo de Aves',
            '/`?consultar_gastos_periodo`?/i' => 'el Control de Gastos',
            '/`?consultar_raciones_alimento`?/i' => 'el registro de alimentación en Producción',
            '/`?consultar_ubicacion_ave`?/i' => 'la consulta de ubicación en Aves',
            '/`?consultar_manuales_tecnicos`?/i' => 'la biblioteca de manuales técnicos',
        ];

        return (string) preg_replace(array_keys($reemplazos), array_values($reemplazos), $texto);
    }
}

