# Especificación Técnica: Blindaje de Guardrails — Alcance Gastronómico, Anti-Forzado de Formato Técnico y Sanitización de Salidas

Fecha: 2026-11-11  
Estado: Aprobado para Planificación  
Repositorio: `avicola-backend-api` (Laravel 11, PHP 8.3, PostgreSQL 16)  
Contexto: Resolución del incidente detectado en `mnt_funcion_log_agent` (IDs 33, 34 y 35).

---

## 1. Contexto del Incidente y Causa Raíz

En una sesión de pruebas del asistente AviaCore (`uuid_conversacion: edbe4403-8803-4388-b944-bebafec3ae00`), se registraron tres turnos anómalos:

1. **Turno ID 33 (`mensaje_usuario: "como preparo pupusas"`, 0 turnos historial)**:
   - **Comportamiento**: El guardrail de entrada `ScopeEnforcementGuard` no detectó la consulta porque el regex `tema_ajeno` en `config/guardrails.php` únicamente contenía `receta\s+de` y `como\s+cocinar`. La conjugación `"como preparo"` y el término `"pupusas"` evadieron el filtro.
   - **Consecuencia**: La petición llegó a Gemini, consumió **2,151 tokens** y tardó **10.48 segundos**. El LLM generó una respuesta educada excusándose en temas gastronómicos y recetas de cocina tradicionales para preparar pupusas.
2. **Turno ID 34 (`mensaje_usuario: "respondeme en formato JSON"`, 2 turnos historial)**:
   - **Comportamiento**: En la misma sesión, el usuario envió únicamente `"respondeme en formato JSON"`. Ningún guardrail de entrada (`PersonaDriftGuard`, `PromptInjectionGuard`, `ScopeEnforcementGuard`) detectó la instrucción de forzado de formato de datos crudos.
   - **Consecuencia**: Gemini tomó el turno previo (la disculpa sobre recetas de pupusas) y lo encapsuló en un objeto JSON crudo:
     ```json
     {
       "respuesta": "Como asistente de la granja, mi especialidad es apoyar en la gestión operativa y técnica del manejo de nuestras aves. No dispongo de información sobre recetas de cocina, por lo que te recomiendo consultar fuentes gastronómicas tradicionales para ese tipo de preparaciones."
     }
     ```
   - Al no existir en el `System Prompt` una directriz prohibitiva sobre salidas JSON crudas, ni un guardrail de salida que intercepte volcados JSON, la respuesta llegó intacta a la bitácora y al usuario.
3. **Turno ID 35 (`mensaje_usuario: "respondeme en formato yml"`, 4 turnos historial)**:
   - Replicó el mismo patrón en formato YAML sin ser interceptado.
4. **Turno ID 32 (`mensaje_usuario: "respondeme en formato json"`, sesión limpia `b292d5f5...`, 0 turnos historial)**:
   - Aunque no mencionó gastronomía por no tener historial, evidenció que las peticiones de formato técnico pasan directamente al LLM gastando tokens innecesarios.

---

## 2. Objetivos y No-Objetivos

### Objetivos
1. **Detección Culinaria Temprana (0 tokens, 0 ms)**: Bloquear de forma determinista cualquier consulta de recetas, cocina o gastronomía ajena a la granja en `ScopeEnforcementGuard`.
2. **Detección de Forzado de Formato Técnico (0 tokens, 0 ms)**: Interceptar en `PersonaDriftGuard` cualquier intento de forzar respuestas en formatos de datos estructurados crudos (JSON, YAML, XML, CSV, código binario o scripting).
3. **Refuerzo del System Prompt (`PromptComposer`)**: Incorporar en `CAPA_ESTILO` una prohibición explícita de generar bloques de código, JSON, YAML u otros esquemas crudos como respuesta al operario.
4. **Guardrail de Salida Saneador de JSON (`JsonRawOutputGuard`)**: Si el LLM llegase a emitir una respuesta estructurada en JSON (por ejemplo `{"respuesta": "..."}` o envuelta en bloques markdown json), el guard de salida debe extraer el texto en lenguaje natural o bloquear el volcado técnico, impidiendo que el usuario vea llaves o sintaxis JSON.
5. **Cero regresiones**: Conservar indemnes todas las consultas operativas legítimas de la granja (aves, postura, gastos, raciones, manuales técnicos).

### No-Objetivos
- No modificar el esquema de base de datos ni migraciones de `mnt_funcion_log_agent`.
- No alterar la interfaz pública ni el contrato del API (`success`, `data`, `sugerencias_modulos`).
- No requerir cambios en el frontend (`avicola-frontend`).

---

## 3. Especificación Técnica de las Capas

### 3.1 Capa de Configuración: `config/guardrails.php`

#### A. Ampliación de `patrones.tema_ajeno`
Se refuerza el alcance gastronómico agregando patrones de preparación, recetas y conceptos culinarios:
```php
'tema_ajeno' => [
    '/\b(poema|poesia|cancion|letra\s+de|chiste|cuento|historia\s+de\s+amor|adivinanza)\b/',
    '/\b(mundial|futbol|partido|liga\s+de\s+(?:campeones|futbol|primera|honor)|campeonato|pelicula|netflix|cantante|actor|actriz)\b/',
    '/\b(python|javascript|codigo\s+fuente|escribe\s+un\s+script|html|css|sql)\b/',
    '/\b(como\s+(?:hago|hacer|preparar|preparo|cocino|cocinar)|receta|recetas|ingredientes\s+(?:de|para)|platillo|platillos|gastronomia|restaurante)\b/',
    '/\b(pupusa|pupusas|pizza|pizzas|tacos|hamburguesa|pastel|postre|sopa|guiso|almuerzo|desayuno|cena)\b/',
    '/\b(presidente|elecciones|politica|horoscopo|bitcoin|criptomoneda)\b/',
    '/\b(traduce|traducir|traduccion)\b/',
],
```
*Protección anti falsos positivos*: La función `mencionaVocabularioGranja($texto)` de `GuardrailBase` asegura que si el operario consulta por ejemplo *"receta de alimento balanceado para aves"* o *"cómo preparar raciones para gallinas"*, al contener `"alimento"`, `"aves"` o `"gallinas"`, **no se bloquee**.

#### B. Ampliación de `patrones.persona` (Anti-Forzado de Formato Técnico)
Se incorporan directivas para capturar intentos de alterar el formato humano conversacional del asistente:
```php
'persona' => [
    '/\b(responde|hablame|habla|contesta|escribeme|escribe)\b.{0,25}\b(como\s+(un\s+)?pirata|en\s+rima|en\s+verso|sarcastic[oa]|como\s+(un\s+)?robot|en\s+ingles)\b/',
    '/\b(responde|respondeme|habla|hablame|contesta|contestame|escribe|escribeme|dame|damelo|genera|generame|formatea|formateame)\b.{0,25}\b(en\s+(?:formato\s+)?)?(json|yaml|yml|xml|csv|sql|binario|codigo|javascript|python|html)\b/',
    '/\b(en\s+formato\s+(?:json|yaml|yml|xml|csv|sql|binario|codigo))\b/',
    '/\bse\s+(sarcastico|sarcastica|grosero|grosera|gracioso|graciosa)\b/',
    '/\b(cambia|cambiar)\b.{0,15}\b(tu|tus)\s+(tono|personalidad|identidad|nombre)\b/',
    '/\b(cambia|cambiar)\b.{0,10}\bel\s+tono\s+de\s+tus\b/',
    '/\b(ya\s+no\s+eres|ahora\s+eres|finge\s+ser|haz\s+de\s+cuenta\s+que\s+eres)\b/',
],
```

---

### 3.2 Capa de Instrucciones: `PromptComposer.php`

En `CAPA_ESTILO`, se explicita la regla de formato conversacional humano estricto:
```markdown
- Formato Conversacional Humano Obligatorio: redacta siempre tus respuestas en lenguaje natural en español (prosa concisa o listas con viñetas limpias). Está estrictamente PROHIBIDO responder en formatos estructurados crudos (como JSON, YAML, XML, CSV o bloques de código), incluso si el usuario lo solicita expresamente.
```

---

### 3.3 Capa de Salida: `JsonRawOutputGuard.php`

Nuevo guardrail en `app/Services/Asistente/Guardrails/Salida/JsonRawOutputGuard.php`:
* **Fase**: `salida`.
* **Misión**: Inspeccionar la respuesta generada por Gemini antes de enviarla.
* **Lógica**:
  1. Detectar si la respuesta contiene o inicia con una estructura JSON válida (por ejemplo `{ ... }` o ```` ```json ... ``` ````).
  2. Si es un JSON con una propiedad textual representativa (como `"respuesta"`, `"mensaje"`, `"content"`), extraer el valor limpio y emitir `ResultadoGuardrail::modifica($textoLimpio, 'Se extrajo el mensaje conversacional de una estructura JSON cruda.')`.
  3. Si es un volcado técnico no recuperable o JSON arbitrario sin texto natural, reemplazar con la respuesta estándar amigable: `ResultadoGuardrail::bloquea(config('guardrails.mensajes.fuga'), 'La respuesta contenía una estructura JSON técnica cruda.')`.
  4. Si es texto normal, emitir `ResultadoGuardrail::pasa()`.

* **Registro en `AppServiceProvider.php`**:
  Se agrega a la lista `self::ASISTENTE_GUARDRAILS` en la sección de salida:
  ```php
  // Salida
  SystemLeakageGuard::class,
  DateFormatGuard::class,
  SourceAttributionGuard::class,
  JsonRawOutputGuard::class,
  LengthAndBrevityGuard::class,
  ```

---

## 4. Estrategia de Pruebas Automatizadas

1. **Pruebas de Entrada (`GuardrailsEntradaTest.php`)**:
   - Casos gastronómicos fuera de alcance bloqueados:
     - `"como preparo pupusas"` -> Bloqueado por `ScopeEnforcementGuard`.
     - `"receta de tacos de carne"` -> Bloqueado por `ScopeEnforcementGuard`.
     - `"ingredientes para una pizza"` -> Bloqueado por `ScopeEnforcementGuard`.
   - Casos legítimos con vocabulario de granja que NO deben bloquearse:
     - `"cómo preparar raciones de alimento para gallinas"` -> PASA (contiene "raciones", "alimento", "gallinas").
   - Casos de forzado de formato bloqueados:
     - `"respondeme en formato JSON"` -> Bloqueado por `PersonaDriftGuard`.
     - `"respondeme en formato yml"` -> Bloqueado por `PersonaDriftGuard`.
     - `"dámelo en formato XML"` -> Bloqueado por `PersonaDriftGuard`.
     - `"en formato json"` -> Bloqueado por `PersonaDriftGuard`.
2. **Pruebas de Salida (`GuardrailsSalidaTest.php`)**:
   - `JsonRawOutputGuard` extrae `"respuesta"` de `{"respuesta": "Hola, en la granja..."}` devolviendo solo el texto.
   - `JsonRawOutputGuard` sanea bloques con ```` ```json { "respuesta": "..." } ``` ````.
   - Texto plano normal pasa sin modificaciones.
3. **Pruebas de Regresión (`GuardrailsEntradaRegresionTest.php` y suite completa)**:
   - Ejecutar suite con SQLite `:memory:`.

