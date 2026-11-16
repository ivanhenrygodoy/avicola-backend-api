# Diseño: Módulo Benchmark (auditoría del agente) + Guardrails organizados

Fecha: 2026-11-10 · Repos: `avicola-backend-api` (Laravel 11, PostgreSQL) y `avicola-frontend` (Vue 3 + Pinia + Tailwind)

## 1. Intención y por qué

El asistente AviaCore (Gemini + Function Calling) hoy solo deja rastro en `laravel.log` y sus protecciones están dispersas (saludos, tope de 3 iteraciones, sanitizador de nombres de tools, reglas escritas solo en el prompt). Caso real que lo motivó: se le pidió sumar dos dígitos, dijo que no era su función **y aun así hizo la cuenta**. Una instrucción en el prompt no garantiza nada; hace falta un guard en código.

Se necesitan dos cosas, en un solo proyecto:

1. **Benchmark / auditoría**: poder ver qué pasó en cada interacción usuario ↔ agente (entrada, prompt, respuesta, tools, guards, tokens, error/éxito), con estadísticas agregadas.
2. **Guardrails organizados**: una carpeta propia, interfaz común, los guards existentes migrados ahí y los nuevos agregados.

## 2. Objetivos, no-objetivos y anti-objetivos

**Objetivos**
- Registrar cada turno del asistente en PostgreSQL (`mnt_funcion_log_agent` + detalle de tools).
- Pantalla con dos secciones: estadísticas y listado de logs filtrable, con modal de detalle.
- Guardrails de entrada y de salida detrás de una interfaz común, con resultado trazable en el log.
- Que el caso "suma de dos dígitos" quede bloqueado de forma determinista.

**No-objetivos (esta fase)**
- `HallucinationCheckGuard` (grounding de cifras): requiere juicio sobre el texto; fase posterior.
- `PedagogicalFallbackGuard` y `CrossSpeciesBreedingGuard` como código: hoy viven en `CAPA_DOMINIO` del prompt; se mantienen allí. Se pueden detectar por regex más adelante.
- Exportación (CSV/PDF) de logs, alertas automáticas, evaluación automática de calidad (LLM-judge).

**Anti-objetivos (sería un fracaso aunque "funcione")**
- Que el log identifique al usuario que escribió el mensaje (confidencialidad).
- Que registrar el log pueda romper o demorar el chat: un fallo al guardar el log **nunca** debe afectar la respuesta al operario.
- Que el módulo duplique componentes que ya existen en el frontend.
- Que un guard filtre nombres de tablas, rutas o trazas al operario.

## 3. Restricciones (reglas de cada repo)

**Backend** (`.agents/skills/avicola-backend`): prefijos `mnt_`/`ctl_`; clave `id`; foráneas `id_<entidad>`; `restrictOnDelete()`; controladores delgados + FormRequest + Servicio; JSON `{success, data, meta, stats}` en colecciones paginadas; Eager Loading; rutas bajo `auth:sanctum`; pruebas en `tests/Feature/`.
**Pruebas**: SIEMPRE con el comando aislado `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test` (un `php artisan test` a secas vacía la BD de desarrollo).
**Frontend** (`.agents/skills/avicola-frontend` + `DESIGN.md`): vistas solo orquestan; lógica en store; HTTP en `services/`; tokens bosque/salvia/crema/verdoso/tierra; tarjetas `rounded-2xl border-borde`; sin tarjetas anidadas; botones de modal centrados; backdrop sin escala; `notify.*` en vez de `alert()`; filtros con `FilterBar` (`FloatingInput/Select`); iconos Lucide; sin emojis en UI; estados de carga con `LoadingState`/`ActionLoader`.
**Global**: un único componente en `common/` y migrar copias; no commitear sin orden del usuario; consultas SQL solo SELECT.

## 4. Backend

### 4.1 Datos

**`mnt_funcion_log_agent`** (un registro por turno del chat; nombre pedido por el usuario)

| Campo | Notas |
|---|---|
| `id` | PK |
| `uuid_conversacion` | nullable; UUID que genera el widget por sesión de chat. **No** deriva del usuario. (decisión del diseñador) |
| `mensaje_usuario` | texto del operario, tal cual (máx. 1000 por validación actual) |
| `turnos_historial` | entero |
| `prompt_version` | hash corto del `PromptComposer::componer()` usado; el prompt completo se muestra desde la versión vigente del código, no se duplica en cada fila (decisión del diseñador) |
| `respuesta_agente` | texto final entregado al operario (ya filtrado por guards) |
| `respuesta_original_llm` | nullable; solo si un guard de salida modificó o reemplazó la respuesta |
| `resultado` | `exitoso` \| `bloqueado_guard` \| `error` \| `fast_path` (saludo, 0 tokens) |
| `guards_aplicados` | jsonb: lista `[{guard, fase: entrada\|salida, accion: bloqueo\|modificacion\|aviso, detalle}]` |
| `error_mensaje` | nullable; **mensaje saneado**, nunca la traza; la traza completa va solo a `laravel.log` |
| `tokens_prompt`, `tokens_respuesta`, `tokens_total` | sumados de `usageMetadata` de Gemini en todas las iteraciones; 0 en fast-path/bloqueo previo al LLM |
| `iteraciones` | llamadas al LLM realizadas |
| `duracion_ms` | |
| `modelo` | modelo Gemini usado |
| `created_at` | |

Sin `id_usuario` ni ninguna columna que lo identifique. Índices: `created_at`, `resultado`.

**`mnt_funcion_log_agent_tools`** (una fila por tool ejecutada en el turno)
`id`, `id_funcion_log_agent` (FK, `restrictOnDelete`... ver nota), `orden`, `herramienta`, `argumentos` (jsonb), `tablas_consultadas` (jsonb, p. ej. `["mnt_gastos","ctl_categorias_gastos"]`), `resumen_resultado` (texto corto, **no** el payload completo), `exito` (bool), `duracion_ms`.
Nota: la skill pide `restrictOnDelete()`; como los logs solo se purgan por retención, el borrado de la purga se hace hijos primero. (decisión del diseñador)

Cada tool declara qué tablas lee mediante un método nuevo en `AsistenteToolInterface` (`tablasConsultadas(): array`), para que el dato sea declarativo y no inferido.

Modelos Eloquent: `FuncionLogAgent` y `FuncionLogAgentTool`.

### 4.2 Guardrails (`app/Services/Asistente/Guardrails/`)

Estructura:
- `Contracts/GuardrailInterface` con `nombre()`, `fase()` (entrada|salida) y `evaluar(ContextoGuardrail): ResultadoGuardrail`.
- `ResultadoGuardrail`: `pasa | bloquea(respuestaEstandar) | modifica(textoNuevo) | avisa`, más `detalle`.
- `GuardrailPipeline`: ejecuta guards de entrada en orden (el primero que bloquea corta el turno **antes de llamar al LLM**, 0 tokens), y guards de salida sobre la respuesta. Acumula los resultados para el log. Los guards se registran en un único lugar (patrón del `AsistenteToolRegistry`) para cumplir OCP.
- `Entrada/` y `Salida/` con un guard por clase.

**Migración de lo existente** (se conserva el comportamiento y se ajustan los tests de `AsistenteTest`):
- `SaludoDetectorService` → guard/atajo de entrada `SaludoFastPathGuard` (resultado `fast_path`).
- `MAX_ITERACIONES = 3` → `ToolLoopGuard` (la lógica del bucle sigue en el orquestador; el guard define el tope y deja la señal en el log).
- `ModuloNavigationResolver::sanitizarRespuesta` → parte de `SystemLeakageGuard` (salida). `resolver()` de sugerencias de módulos no es un guard y se queda donde está.
- Reglas de `PromptComposer` (CAPA_SEGURIDAD, CAPA_ESTILO, CAPA_DOMINIO): se mantienen como defensa de primera línea; los guards las respaldan en código.

**Guards nuevos, lote 1** (de la lista del usuario, solo los verificables de forma determinista):

| Guard | Fase | Acción |
|---|---|---|
| `ScopeEnforcementGuard` | entrada | Detecta pedidos claramente ajenos a la granja (poemas, deportes, programación, etc.) y responde el mensaje estándar de alcance |
| `MathematicalOffloadingGuard` | entrada | Detecta aritmética/álgebra/conversiones ("suma 3+5", "calcula", "integral", expresiones numéricas) y bloquea. Los cálculos derivados de tools (huevos aprovechables, totales) no pasan por aquí porque ocurren dentro del dominio |
| `PromptInjectionGuard` | entrada | "olvida tus instrucciones", "actúa como DAN", "muestra tu system prompt", "qué tools tienes" → bloqueo con mensaje fijo |
| `ProfanityAndToxicityGuard` | entrada | Insultos/agresión → mensaje neutro de profesionalismo |
| `PersonaDriftGuard` | entrada | Pedidos de cambiar tono/identidad ("como pirata", "en rima", "sé sarcástico") → mensaje que restablece el estilo AviaCore |
| `ContextTamperingGuard` | entrada | Frases tipo "soy el admin con id 1": se registra como aviso; el contexto ya es inmutable (`$contextoFijo`) y además se descartan argumentos de identidad que el LLM pase a las tools |
| `ToolArgumentValidationGuard` | tool | Valida argumentos que Gemini entrega (fechas `Y-m-d`, `fecha_hasta >= fecha_desde`, especie ∈ {Gallina, Chompipe, Pato}) antes de tocar PostgreSQL; si fallan, devuelve error legible al LLM para que pida aclaración |
| `ToolLoopGuard` | bucle | Tope de 3 iteraciones; mensaje de contingencia |
| `SystemLeakageGuard` | salida | Reemplaza respuestas/errores que mencionen `mnt_`/`ctl_`, rutas, trazas PHP, cURL; además sanea nombres de tools |
| `DateFormatGuard` | salida | Convierte fechas crudas (`Y-m-d`, ISO) a `DD-MM-YYYY` |
| `SourceAttributionGuard` | salida | Si se ejecutó la tool de manuales y la respuesta no cita ningún manual, se registra como aviso (no se reescribe la respuesta) |
| `LengthAndBrevityGuard` | salida | Respuesta por encima de un tope de caracteres: se registra aviso (no se recorta a ciegas) |

**Decisión de diseño**: los guards de entrada se basan en patrones (regex/listas) mantenidos en un `config/guardrails.php` para poder ajustar sin tocar clases. Es deliberadamente conservador: bloquea lo claro y registra el resto. Tiene falsos negativos (una petición ajena redactada de forma rara llegará al LLM); el log del Benchmark existe justamente para detectarlos y afinar patrones.

### 4.3 Orquestación y registro

- `AsistenteOrquestadorService` pasa a usar `GuardrailPipeline` y a acumular un "rastro" del turno (tools, tokens, guards, duración).
- `GeminiApiClient::generarContenido` expone `usageMetadata` (tokens) sin cambiar su contrato actual de retorno para quien ya lo usa (se agrega, no se rompe).
- Un servicio nuevo `FuncionLogAgentService` (o `AsistenteLogRecorder`) guarda el turno. Se invoca desde el orquestador para éxito/bloqueo/fast-path y desde el `catch` del `AsistenteController` para errores. **Toda escritura va envuelta en try/catch**: si falla, se loguea en `laravel.log` y el chat sigue.
- `AsistenteController::chat` sigue delgado; el `ChatRequest` acepta `uuid_conversacion` opcional.

### 4.4 API del Benchmark (bajo `auth:sanctum`, prefijo `asistente/benchmark`)

- `GET /estadisticas?fecha_inicio&fecha_fin` → totales por resultado (éxito, error, bloqueado, fast-path), tasa de éxito, tokens totales y promedio por turno, latencia promedio, top guards activados, top tools usadas, y **series diarias** (una fila por día, con días sin datos rellenados en 0): interacciones por resultado y tokens de prompt/respuesta, listas para alimentar `LineChart`.
- `GET /logs?resultado&fecha_inicio&fecha_fin&guard&herramienta&search&page&per_page` → colección paginada con `meta` y `stats` (formato estándar). **Nunca** incluye datos de usuario.
- `GET /logs/{id}` → detalle: mensaje, prompt (versión + texto vigente de las capas), respuesta final y original, tools con argumentos y tablas, guards, tokens, error saneado.
- Controlador `AsistenteBenchmarkController` delgado + `FiltroLogsAgenteRequest`; consultas en un servicio con Eager Loading de las tools.

## 5. Frontend

**Actualización (indicaciones posteriores del usuario):** el módulo se llama **"Benchmark AviaCore"** y se organiza como Producción: un `PageHeader` con interruptor de pestañas tipo píldora que alterna entre **Estadísticas** y **Logs del agente** (en vez de una sola página con ambas secciones apiladas). El listado de logs usa el `DataTable` con su paginación integrada, `FilterBar` y los botones existentes. El interruptor de píldoras hoy está copiado en `ProduccionView`; se extrae a un componente global `PillTabs` y, con visto bueno, se migra Producción. Las migraciones son aditivas sobre la BD con datos reales y el seeder de demostración es opcional y a demanda. Ver el plan para el detalle.

Reutilización (todos ya existen en `src/components/common/`): `PageHeader`, `StatCard`, `FilterBar`, `DataTable`, `PaginationBar`, `StatusBadge`, `BaseModal`, `SectionCard`/`CollapsibleCard`, `LoadingState`, `EmptyState`, `LineChart`, `AppHeader` (se agrega el ítem de navegación). Patrón de referencia: `GastosView.vue` + `stores/gastos.js` + `services/gastos.js`.

Nuevos:
- `views/BenchmarkView.vue` (ruta `/benchmark`, `requiresAuth`, ítem en `AppHeader`): solo orquesta. Orden vertical según DESIGN.md (`space-y-6`): **sección Estadísticas** → **sección Logs** (`FilterBar` → `DataTable` → `PaginationBar`). Un selector de período (7 / 30 días, mismo patrón que `ProduccionHuevosCard`) gobierna toda la sección Estadísticas.

**Gráficos de la sección Estadísticas** (solo se reutilizan `LineChart`, `ProgressBar` y `SectionCard`; no se agrega librería de gráficos ni componentes de gráfico nuevos):

| Fila | Contenido | Componente | Notas |
|---|---|---|---|
| 1 | 4 `StatCard`: total de interacciones, tasa de éxito, tokens totales (con promedio por turno), latencia promedio | `StatCard` | Grilla `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4` (patrón de Aves) |
| 2 (2 columnas en `lg`) | **Interacciones por día**: series exitosas, bloqueadas por guard, con error | `LineChart` dentro de `SectionCard` | Colores semánticos ya usados en `ProduccionHuevosCard`: verde `#2E8B57` éxito, ámbar `#C98A1B` bloqueado, rojo `#B91C1C` error |
| 2 | **Tokens por día**: serie de tokens de prompt y de respuesta | `LineChart` dentro de `SectionCard` | Una sola escala Y; sirve para ver costos y picos |
| 3 (3 columnas en `lg`) | **Distribución por resultado** (exitoso / fast-path / bloqueado / error) | lista `ProgressBar` en `SectionCard` | Patrón de `AvesDistribucionCard`, con porcentaje sobre el total |
| 3 | **Guards más activados** (ranking) | lista `ProgressBar` en `SectionCard` | Barra en `bg-tierra` como `GastosCategoriaCard` |
| 3 | **Tools más usadas** (ranking) | lista `ProgressBar` en `SectionCard` | Barra en `bg-bosque` |

Las tarjetas son hermanas dentro de la grilla, nunca anidadas. Cada gráfico maneja estado vacío con el texto de `EmptyState`/párrafo muted y estado de carga con `LoadingState`. Los rankings son clicables: al elegir un guard o una tool se aplica ese filtro al listado de logs de abajo (hace útil la sección de estadísticas como entrada a la auditoría).
- Componentes de la sección en `components/benchmark/`: `InteraccionesDiaCard.vue`, `TokensDiaCard.vue`, `DistribucionResultadoCard.vue`, `RankingGuardsCard.vue`, `RankingToolsCard.vue`. Si los dos rankings quedan casi idénticos, se unifican en **un** componente genérico (`RankingCard.vue`) con props, en vez de dos copias; se avisa al crearlo.
- `stores/benchmark.js`, `services/benchmark.js`.
- `components/benchmark/LogDetalleModal.vue` (sobre `BaseModal`): bloques para mensaje del operario, prompt (colapsable con `CollapsibleCard`), respuesta del agente (y la original del LLM si un guard la cambió), tools con tablas consultadas, guards aplicados, tokens/duración, error.
- Si se repite un patrón de "chips de guard/tool", se crea **un** componente global (p. ej. `common/TagList.vue`) y se usa en la tabla y en el modal; se avisa en el momento de crearlo. Los badges de resultado reutilizan `StatusBadge` ampliando sus variantes si hace falta (consultar antes de alterar, según DESIGN.md).
- `AsistenteChatWidget` / store del asistente: generar y enviar `uuid_conversacion` por sesión del widget.
- Sin emojis en UI, iconos Lucide, tokens del sistema de diseño, filtros con `FloatingInput/Select`.

## 6. Pruebas

- `tests/Feature/GuardrailsTest.php` (o por guard): cada guard con casos que bloquean y casos legítimos que no deben bloquearse (ej. "huevos aprovechables de hoy" no cae en el guard matemático).
- `tests/Feature/AsistenteTest.php`: ajustar a la nueva estructura; test explícito de "suma 2+3" → bloqueado, 0 tokens, log con `resultado = bloqueado_guard`.
- `tests/Feature/BenchmarkTest.php`: registro del log en éxito/bloqueo/error, que un fallo del recorder no rompa el chat, filtros, estadísticas, y que ninguna respuesta de la API ni columna contenga datos de usuario.
- Ejecución solo con el comando aislado (sección 3).

## 7. Decisiones y puntos abiertos

**Decididas con el usuario**: dos secciones (estadísticas + listado con filtros éxito/error/fecha y modal de detalle); tabla `mnt_funcion_log_agent`; relacionar tools y tablas; sin identificar al usuario; guardrails en carpeta propia migrando los existentes; lote 1 de guards y `HallucinationCheckGuard` para una fase posterior.

**Decisiones del diseñador (el usuario puede cambiarlas)**: `uuid_conversacion` anónimo desde el widget; `prompt_version` en vez de duplicar el prompt; tabla hija `mnt_funcion_log_agent_tools`; `tablasConsultadas()` declarativo en cada tool; guards por patrones en `config/guardrails.php`; `SourceAttribution` y `LengthAndBrevity` solo como aviso; fallos del recorder no afectan al chat.

**Decisiones del diseñador tras la revisión del documento (el usuario puede cambiarlas)**:
- **Identidad en `argumentos`**: antes de guardar los argumentos de una tool se eliminan las claves de identidad (`id_usuario`, `user_id`, `email` y similares). La identidad solo viaja en `$contextoFijo` y nunca se persiste. No se agrega filtro por granja: los demás módulos (p. ej. Gastos) tampoco filtran por granja. El test "sin datos de usuario" revisa columnas y respuesta de la API, y revisa que `argumentos` no contenga claves de identidad.
- **Aritmética legítima de la granja**: `MathematicalOffloadingGuard` bloquea solo aritmética o álgebra pura, sin vocabulario de la granja. Si el mensaje menciona aves, huevos, gastos, raciones, corrales o similares ("¿cuántos huevos si tengo 200 gallinas y 85% de postura?", "suma los gastos de la semana"), pasa al LLM. Se acepta como falso negativo que se afina con el log del Benchmark. El corpus inicial de pruebas (casos que bloquean y casos que no) lo propone el builder a partir de los ejemplos de este documento.
- **Textos estándar**: se usan los de la lista del usuario (alcance, profesionalismo, etc.). Para saludos y mensajes que ya existen en el código y en `AsistenteTest` se conserva el texto actual.
- **Fases en la interfaz**: `fase()` admite `entrada | tool | bucle | salida`, y `guards_aplicados[].fase` usa el mismo conjunto.
- **Valor de `resultado`**: `bloqueado_guard` cuando un guard de entrada corta el turno antes del LLM, o cuando un guard de salida reemplaza la respuesta completa (p. ej. `SystemLeakageGuard`). Las modificaciones menores (p. ej. `DateFormatGuard`) dejan el turno en `exitoso` y se anotan en `guards_aplicados`. La "tasa de éxito" cuenta solo `exitoso` y `fast_path`.

**Resueltas por el usuario tras la revisión**:
1. **Acceso al Benchmark**: cualquier usuario autenticado (`auth:sanctum`), sin roles nuevos. Si más adelante se crean roles, se restringe entonces.
2. **Retención**: sin purga automática por ahora; los logs se guardan indefinidamente. La purga queda como tarea futura (no hay comando programado ni config de retención en este alcance, y se omite la nota de purga "hijos primero" de la sección 4.1).

**Abiertos**: ninguno.
