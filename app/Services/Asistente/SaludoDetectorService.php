<?php

namespace App\Services\Asistente;

/**
 * Detecta saludos, despedidas y fórmulas de cortesía puras para responder de forma predeterminada,
 * ahorrando tokens de LLM, eliminando latencia y evitando saturación de cuota en el proveedor de IA.
 */
class SaludoDetectorService
{
    /**
     * Patrones regex para saludos y cortesías puras sin consulta técnica ni operativa.
     *
     * @var array<int, string>
     */
    private const PATRONES_SALUDO_PURO = [
        // Saludos informales y formales
        '/^(hola+|holas+|ole+|buenas+|buenas\s+tardes|buenos\s+dias|buenos\s+días|buenas\s+noches|saludos|que\s+tal|qu[eé]\s+tal|hola\s+buenas|hola\s+que\s+tal|buen\s+dia|buen\s+d[ií]a|hey|alo|al[oó]|hello|hi)$/iu',
        // Expresiones de cortesía y agradecimiento
        '/^(gracias+|muchas\s+gracias|mil\s+gracias|gracias\s+por\s+tu\s+ayuda|agradecido|te\s+lo\s+agradezco|ok\s+gracias|muchas\s+gracias\s+aviacore)$/iu',
        // Despedidas
        '/^(adios+|adi[oó]s+|chao+|hasta\s+luego|nos\s+vemos|hasta\s+pronto|hasta\s+ma[nñ]ana|bye|bye\s+bye)$/iu',
        // Preguntas sobre identidad / rol del asistente
        '/^(qui[eé]n\s+eres|qu[eé]\s+eres|c[oó]mo\s+te\s+llamas|cu[aá]l\s+es\s+tu\s+nombre|pres[eé]ntate|presentate)$/iu',
    ];

    /**
     * Determina si el mensaje del usuario es únicamente un saludo o cortesía sin consulta.
     */
    public function esSaludoPuro(string $mensaje): bool
    {
        $limpio = trim(preg_replace('/^[¿¡\s]+|[.!?\s]+$/u', '', mb_strtolower($mensaje)));
        if ($limpio === '') {
            return false;
        }

        foreach (self::PATRONES_SALUDO_PURO as $patron) {
            if (preg_match($patron, $limpio) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Genera la respuesta predeterminada cálida y contextual sin invocar al LLM.
     */
    public function obtenerRespuestaPredeterminada(string $mensaje): string
    {
        $limpio = trim(mb_strtolower($mensaje));

        // 1. Agradecimiento
        if (preg_match('/gracias|agradec/iu', $limpio) === 1) {
            return '¡Con mucho gusto! 🐔 Estoy a tu disposición para cualquier consulta operativa, postura, alimentación o sanidad de la granja cuando lo necesites. ¡Que tengas un excelente día!';
        }

        // 2. Despedida
        if (preg_match('/adios|adi[oó]s|chao|hasta|bye/iu', $limpio) === 1) {
            return '¡Hasta pronto! Aquí estaré disponible cuando requieras revisar inventarios o consultar manuales técnicos de la granja. ¡Éxitos en las labores!';
        }

        // 3. Presentación / Identidad
        if (preg_match('/qui[eé]n\s+eres|qu[eé]\s+eres|llamas|nombre|present/iu', $limpio) === 1) {
            return '¡Hola! 👋 Soy AviaCore, el Asistente Inteligente de la Granja Avícola. Puedo asistirte en tiempo real con inventario de aves, producción de huevos, raciones de alimento, control de gastos y manuales técnicos zootécnicos. ¿En qué te puedo colaborar hoy?';
        }

        // 4. Saludo general (Hola, buenos días, buenas tardes, saludos)
        return '¡Hola! 👋 Soy AviaCore, tu asistente virtual para la granja. Puedo ayudarte con el censo de aves, postura de huevos, alimentación, finanzas y literatura técnica. ¿Qué deseas consultar hoy?';
    }
}

