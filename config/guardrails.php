<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mensajes estándar de los guardrails del asistente AviaCore
    |--------------------------------------------------------------------------
    */
    'mensajes' => [
        'alcance' => 'Solo puedo asistirte en temas operativos de la granja (aves, huevos, alimentación, gastos) y consultas zootécnicas de nuestros manuales.',
        'profesionalismo' => 'Mantengamos una comunicación profesional. ¿En qué tarea de la granja te puedo colaborar?',
        'persona' => 'Soy AviaCore, el asistente de la granja. Mantengo un trato profesional, técnico y conciso para ayudarte con la operación. ¿Qué necesitas consultar?',
        'injection' => 'Solo puedo operar bajo mis directrices de trabajo asignadas. ¿En qué tarea de la granja te puedo colaborar?',
        'matematica' => 'No realizo cálculos ajenos a la granja. Puedo ayudarte con totales de huevos, raciones o gastos a partir de los registros del sistema.',
        'fuga' => 'No pude completar esa respuesta de forma segura. Por favor intenta reformular tu consulta sobre la granja.',
        'limite_tools' => 'Se ha alcanzado el límite de consultas consecutivas a las herramientas de la granja. Por favor intenta reformular tu pregunta.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Longitud máxima recomendada de una respuesta (solo genera aviso, no recorta)
    |--------------------------------------------------------------------------
    */
    'largo_maximo_respuesta' => 700,

    /*
    |--------------------------------------------------------------------------
    | Vocabulario de la granja
    |--------------------------------------------------------------------------
    | Si el mensaje contiene alguno de estos términos se considera dentro del dominio
    | y los guards de alcance/matemática no lo bloquean. Se comparan en minúsculas y sin acentos.
    */
    'vocabulario_granja' => [
        'ave', 'aves', 'gallina', 'gallinas', 'gallo', 'gallos', 'pollo', 'pollos', 'pollito', 'pollitos',
        'chompipe', 'chompipes', 'pavo', 'pato', 'patos', 'pichiche', 'pichiches',
        'huevo', 'huevos', 'postura', 'ponedora', 'ponedoras', 'nido', 'nidos',
        'racion', 'raciones', 'alimento', 'alimentos', 'alimentacion', 'concentrado', 'comida',
        'gasto', 'gastos', 'costo', 'costos', 'compra', 'compras', 'proveedor',
        'corral', 'corrales', 'casita', 'casitas', 'division', 'divisiones', 'granja',
        'manual', 'manuales', 'sanidad', 'vacuna', 'vacunas', 'enfermedad', 'histomoniasis',
        'bioseguridad', 'peso', 'inventario', 'especie', 'especies', 'traslado',
        'roto', 'rotos', 'rotura', 'rompieron',
    ],

    /*
    |--------------------------------------------------------------------------
    | Raíces del vocabulario de la granja
    |--------------------------------------------------------------------------
    | Una palabra que empiece con alguna de estas raíces cuenta como vocabulario de la granja
    | (recolectó, recolectados, producción, vacunación, registrado, kilos…).
    */
    'vocabulario_prefijos' => [
        'recolect', 'produc', 'vacun', 'registr', 'kilo', 'maiz', 'sanit', 'zootec',
    ],

    /*
    |--------------------------------------------------------------------------
    | Patrones de los guards de entrada (regex sobre texto normalizado: minúsculas,
    | sin acentos y espacios colapsados)
    |--------------------------------------------------------------------------
    */
    'patrones' => [
        'prompt_injection' => [
            '/\b(olvida|ignora|omite|descarta)\b.{0,30}\b(instrucciones|reglas|indicaciones|directrices)\b/',
            '/\bactua\s+como\b.{0,20}\b(dan|jailbreak|sin\s+restricciones)\b/',
            '/\b(modo|eres)\s+(dan|desarrollador|dios)\b/',
            '/\b(muestra|muestrame|dime|revela|imprime|repite|ensename)\b.{0,30}\b(system\s*prompt|prompt\s+del\s+sistema|instrucciones\s+del\s+sistema|tus\s+instrucciones|tus\s+reglas)\b/',
            '/\b(que|cuales)\s+(tools|herramientas|funciones)\s+(tienes|usas|posees)\b/',
            '/\bsystem\s*prompt\b/',
        ],
        'toxicidad' => [
            '/\b(idiota|imbecil|estupido|estupida|inutil|maldito|maldita|mierda|puta|puto|pendejo|pendeja|cabron|carajo|hijueputa|malparido)\b/',
            '/\b(callate|cierra\s+la\s+boca)\b/',
        ],
        'persona' => [
            '/\b(responde|hablame|habla|contesta|escribeme|escribe)\b.{0,25}\b(como\s+(un\s+)?pirata|en\s+rima|en\s+verso|sarcastic[oa]|como\s+(un\s+)?robot|en\s+ingles)\b/',
            '/\bse\s+(sarcastico|sarcastica|grosero|grosera|gracioso|graciosa)\b/',
            '/\b(cambia|cambiar)\b.{0,15}\b(tu|tus)\s+(tono|personalidad|identidad|nombre)\b/',
            '/\b(cambia|cambiar)\b.{0,10}\bel\s+tono\s+de\s+tus\b/',
            '/\b(ya\s+no\s+eres|ahora\s+eres|finge\s+ser|haz\s+de\s+cuenta\s+que\s+eres)\b/',
        ],
        'suplantacion' => [
            '/\bsoy\s+(el\s+|la\s+)?(admin|administrador|administradora|superusuario|root|dueno|duena|gerente)\b/',
            '/\b(id|usuario)\s*(de\s+usuario\s*)?(=|:|es|numero)?\s*\d+\b.{0,40}\b(admin|todos|otros)\b/',
            '/\bmuestrame\s+(los\s+datos|la\s+informacion)\s+de\s+(otro|otros|todos)\b/',
        ],
        // Fechas (09/11, 01-11, 2026-11-09, 09-11-2026): se quitan antes de buscar operaciones para que
        // el "/" o "-" de una fecha no se confunda con una división o una resta.
        'fecha' => [
            '/\b\d{4}-\d{2}-\d{2}\b/',
            '/\b(?:0?[1-9]|[12]\d|3[01])[-\/](?:0?[1-9]|1[0-2])(?:[-\/]\d{2,4})?\b/',
        ],
        'aritmetica_expresion' => [
            '/\d+(?:[.,]\d+)?\s*[+\-*\/x×÷^]\s*\d+/',
            // Números (cifras o palabras) unidos por un operador dicho en palabras: "23 mas 45", "dos por tres"
            '/\b(?:\d+(?:[.,]\d+)?|cero|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|veinte|treinta|cuarenta|cincuenta|cien|mil)\s+(?:mas|menos|por|entre|dividido\s+(?:por|entre)|multiplicado\s+por)\s+(?:\d+(?:[.,]\d+)?|cero|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|veinte|treinta|cuarenta|cincuenta|cien|mil)\b/',
            '/\bcuanto\s+(?:es|da|son|seria|sale|resulta)\s+(?:\d+|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\s+y\s+(?:\d+|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\b/',
        ],
        'aritmetica_verbo' => [
            '/\b(suma|sumame|sumar|resta|restame|restar|multiplica|multiplicame|multiplicar|divide|dividir|calcula|calcular|calculame|resuelve|resolver)\b/',
            '/\b(integral|derivada|ecuacion|algebra|raiz\s+cuadrada|logaritmo|seno|coseno|factorial)\b/',
            '/\braiz\s+(?:cuadrada\s+)?de\s+\d/',
            '/\bcuanto\s+(?:es|da|son|seria|sale|resulta)\b.{0,25}\b(mas|menos|por|entre|dividido|multiplicado)\b/',
        ],
        'tema_ajeno' => [
            '/\b(poema|poesia|cancion|letra\s+de|chiste|cuento|historia\s+de\s+amor|adivinanza)\b/',
            '/\b(mundial|futbol|partido|liga\s+de\s+(?:campeones|futbol|primera|honor)|campeonato|pelicula|netflix|cantante|actor|actriz)\b/',
            '/\b(python|javascript|codigo\s+fuente|escribe\s+un\s+script|html|css|sql)\b/',
            '/\b(receta\s+de|como\s+cocinar|presidente|elecciones|politica|horoscopo|bitcoin|criptomoneda)\b/',
            '/\b(traduce|traducir|traduccion)\b/',
        ],
    ],
];
