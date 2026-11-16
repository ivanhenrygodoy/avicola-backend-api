# Blindaje de Guardrails: Alcance Gastronómico y Anti-Forzado de Formato Técnico — Plan de Implementación

**Estado:** Finalizado con éxito

> **Estado: COMPLETADO ✅.** Plan de ajuste SDD implementado y verificado con 123 pruebas automáticas pasando en SQLite en memoria.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Evitar de forma determinista que consultas culinarias ajenas (ej. "como preparo pupusas") lleguen al LLM y que peticiones de forzado de formato técnico (ej. "respondeme en formato JSON") manipulen el estilo conversacional o generen respuestas con sintaxis JSON cruda.

**Architecture:**
1. Entrada: Ampliar `tema_ajeno` en `config/guardrails.php` para atrapar consultas de cocina/recetas (`ScopeEnforcementGuard`), y ampliar `persona` para interceptar forzado de formato (`PersonaDriftGuard`).
2. Prompt: Agregar directriz estricta en `PromptComposer.php` prohibiendo respuestas en JSON/estructuras crudas.
3. Salida: Crear `JsonRawOutputGuard` en `app/Services/Asistente/Guardrails/Salida/` registrado en `AppServiceProvider.php` para sanitizar o extraer texto si el modelo llegara a emitir JSON crudo.
4. Pruebas: Feature tests dedicados para cada capa en `GuardrailsEntradaTest` y `GuardrailsSalidaTest`.

**Tech Stack:** Laravel 11, PHP 8.3, Pest/PHPUnit, SQLite en memoria para tests aislados.

**Spec:** `docs/superpowers/specs/2026-11-11-blindaje-guardrails-formato-y-alcance.md`.

---

## Restricciones y Reglas Globales

- **NO HACER COMMIT**: No realizar ningún commit en Git bajo ninguna circunstancia hasta que el usuario dé su aprobación explícita al final.
- **Pruebas backend aisladas**: Siempre ejecutar con SQLite en memoria:
  `docker compose -f avicola-backend-api/docker-compose.yml exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test`
- **Pint**: Correr únicamente sobre los archivos nuevos o modificados en este plan.

---

## Tareas de Implementación

### Tarea 1: Patrones de Entrada en `config/guardrails.php`
- [x] **1.1** Ampliar el array `patrones.tema_ajeno` con términos culinarios, gastronómicos y recetas ajenas a la granja (`como preparo`, `pupusas`, `recetas`, `ingredientes para`, etc.).
- [x] **1.2** Ampliar el array `patrones.persona` con expresiones de forzado de formato técnico (`en formato json`, `en formato yml`, `en json`, `en yaml`, etc.).

### Tarea 2: Refuerzo del System Prompt en `PromptComposer.php`
- [x] **2.1** Modificar `CAPA_ESTILO` en `app/Services/Asistente/PromptComposer.php` agregando la directriz de formato conversacional humano obligatorio (prohibición de JSON, YAML o esquemas técnicos).

### Tarea 3: Guardrail de Salida `JsonRawOutputGuard.php`
- [x] **3.1** Crear `app/Services/Asistente/Guardrails/Salida/JsonRawOutputGuard.php` implementando `GuardrailInterface` (fase `salida`).
- [x] **3.2** Implementar detección de JSON crudo (tanto texto plano `{...}` como bloques ```` ```json ````):
  - Si contiene campo `"respuesta"` o equivalente, extraer el texto limpio.
  - Si es JSON arbitrario o volcado de código, bloquearlo con el mensaje de contingencia.
  - Si es texto natural normal, pasar sin cambios.
- [x] **3.3** Registrar `JsonRawOutputGuard::class` en `AppServiceProvider.php` dentro de `self::ASISTENTE_GUARDRAILS`.

### Tarea 4: Pruebas Unitarias y de Integración
- [x] **4.1** Agregar casos en `tests/Feature/GuardrailsEntradaTest.php`:
  - Casos gastronómicos (`"como preparo pupusas"`, `"receta de tacos"`) bloqueados por `ScopeEnforcementGuard`.
  - Casos legítimos con vocabulario de granja (`"cómo preparar raciones de alimento para aves"`) pasando normalmente.
  - Casos de forzado de formato (`"respondeme en formato JSON"`, `"respondeme en formato yml"`) bloqueados por `PersonaDriftGuard`.
- [x] **4.2** Agregar casos en `tests/Feature/GuardrailsSalidaTest.php`:
  - Extracción de mensaje conversacional desde un objeto JSON crudo `{"respuesta": "..."}`.
  - Sanitización de bloques Markdown ```` ```json { "respuesta": "..." } ``` ````.
  - Paso inalterado de respuestas normales en texto plano.
- [x] **4.3** Ejecutar la suite de pruebas completa en SQLite `:memory:`.

### Tarea 5: Estilo y Verificación
- [x] **5.1** Ejecutar Pint sobre los archivos modificados: `config/guardrails.php`, `PromptComposer.php`, `JsonRawOutputGuard.php`, `AppServiceProvider.php`, `GuardrailsEntradaTest.php`, `GuardrailsSalidaTest.php`.
- [x] **5.2** Verificar `git status` para comprobar que los archivos modificados correspondan exactamente al plan y que **NO se haya realizado ningún commit**.
- [x] **5.3** Presentar el resumen detallado al usuario.

