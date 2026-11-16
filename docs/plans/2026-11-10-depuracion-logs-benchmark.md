# Limpieza de histórico del Benchmark AviaCore — Plan de Implementación

**Estado:** Finalizado con éxito

> **Estado: IMPLEMENTADO ✅.** Reemplaza al plan anterior de depuración *automática* (comando programado). La depuración **manual desde la pantalla** con botón "Limpiar historial" y modal de vista previa está completamente implementada y probada. La parte automática queda como fase 2 opcional (ver el final).

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un usuario pueda borrar desde Benchmark las interacciones antiguas del asistente (anteriores a 1 año, 6 meses o 3 meses), viendo antes cuántas se eliminarán y sin poder tocar nunca los últimos 3 meses.

**Architecture:** Un servicio `DepuracionLogsAgenteService` calcula un corte por meses, cuenta (vista previa) y borra por lotes, hijos antes que padres. Un controlador nuevo expone `GET /asistente/benchmark/depuracion?meses=` (vista previa) y `DELETE` (borrado); el controlador de lectura del Benchmark no se toca. En el frontend, el botón "Limpiar historial" de la barra de filtros abre un modal nuevo (`LimpiezaHistoricoModal`, sobre `BaseModal`) con un `FormField` select de tres opciones, el conteo previo y la confirmación.

**Tech Stack:** Laravel (FormRequest, servicio, `DB::transaction`), PostgreSQL / SQLite en pruebas, Vue 3 + Pinia + Tailwind.

**Spec:** `docs/superpowers/specs/2026-11-10-benchmark-guardrails-asistente-design.md` (dejó la purga como tarea futura, §2 y §7). Plan base: `docs/superpowers/plans/2026-11-10-benchmark-guardrails-asistente.md`.

## Diseño refinado (lo propuesto por el usuario + ajustes)

**Propuesta del usuario:** botón "Limpiar historial" en la barra de filtros → modal pequeño "Limpieza de histórico" → tres opciones (1 año, 6 meses, 3 meses atrás) para no borrar lo actual.

**Ajustes que se agregan y por qué:**
1. **Vista previa antes de borrar:** al elegir una opción, el modal muestra cuántas interacciones y herramientas se eliminarán y la fecha de corte. Si son 0, el botón de confirmar queda deshabilitado. Es la protección más barata contra un borrado equivocado.
2. **Las tres opciones son una lista cerrada en el servidor (3, 6 y 12 meses):** el backend rechaza cualquier otro valor con 422. Así los últimos 3 meses nunca se pueden borrar, ni por error del frontend ni llamando a la API directamente.
3. **Opción por defecto: 12 meses** (la que menos borra).
3b. **Selector de rangos = tres píldoras, no un checkbox ni una lista desplegable.** Las opciones son excluyentes (borrar lo anterior a 3 meses ya incluye lo anterior a 6 y 12), así que un checkbox induciría a error; la lista desplegable oculta las opciones y agrega un clic. Las píldoras muestran las tres a la vez y reutilizan el componente `RangeToggle` (el mismo selector 7/30 días de Estadísticas), con `unitLabel="meses"`, de modo que no se crea ningún componente nuevo y la pantalla mantiene un solo patrón de selección de rango.
4. **El botón de confirmar dice cuántos borra** ("Eliminar 128 registros") y el modal avisa que es irreversible.
5. **Rastro de quién limpió:** una línea en `laravel.log` con el usuario que ejecutó la limpieza, los meses, el corte y los conteos (nunca el contenido de los mensajes). No cambia la regla de confidencialidad: la bitácora sigue sin saber quién escribió cada mensaje.
6. **Después de borrar:** aviso de éxito con el número real eliminado y recarga de logs y estadísticas.

```text
┌ Limpieza de histórico ───────────────────────── ✕ ┐
│  ⚠ Se eliminarán de forma permanente las          │
│    interacciones del asistente más antiguas.      │
│                                                   │
│  ELIMINAR LAS ANTERIORES A                        │
│     ┌──────────┬──────────┬────────────┐          │
│     │ 3 meses  │ 6 meses  │ ■ 12 meses │          │
│     └──────────┴──────────┴────────────┘          │
│                                                   │
│  Se eliminarán 128 interacciones (212             │
│  herramientas) anteriores al 10-11-2025.          │
│  Se conservan las de los últimos 12 meses.        │
│                                                   │
│        [ Cancelar ]  [ Eliminar 128 registros ]   │
└───────────────────────────────────────────────────┘
```

## Decisiones (resueltas por el usuario)

El usuario resolvió: **por ahora cualquier usuario autenticado puede borrar** (decisión 1), y adoptó las propuestas del diseñador para la ubicación del botón (decisión 2, y 3 sobre el modal) y el color (decisión 4). La duda restante, cómo se eligen los rangos, queda resuelta en la decisión 6. Se conservan abajo los motivos de cada una.


1. **Sin roles, cualquier usuario autenticado puede borrar historial anterior a 3 meses.** Es coherente con el acceso actual al Benchmark (spec §7), y está acotado por la lista cerrada de meses, la vista previa, la confirmación y el rastro en `laravel.log`. Restringirlo de verdad exige crear roles (no existen hoy). **Propuesta:** aceptarlo ahora y restringirlo cuando haya roles.
2. **Etiqueta del botón:** "Limpiar historial" queda junto al botón "Limpiar" de los filtros, que solo borra los campos de búsqueda. Pueden confundirse. Se mitiga con un icono distinto (`Eraser`) y el aviso del modal. **Alternativa:** "Depurar historial". **Propuesta:** mantener la etiqueta del usuario.
3. **Modal nuevo en lugar de extender `BaseConfirmModal`:** ese componente no tiene ranura para contenido propio ni forma de deshabilitar el botón, y lo usan 4 pantallas (DESIGN.md pide consultar antes de alterar componentes establecidos). **Propuesta:** modal nuevo sobre `BaseModal`, como `AveBajaModal`. Si se prefiere extender `BaseConfirmModal` (una ranura y una prop opcionales), es un cambio compatible pero toca un componente global.
4. **Color del botón "Eliminar" y del aviso (verificado en el código):** `.btn-destructivo` en `style.css` es **verde bosque** (`#244B36`), igual que `.btn-principal`, porque así lo define la skill del frontend. En la práctica los otros modales de baja pintan rojo a mano (`AveBajaModal` usa `bg-red-700`; hay 20 usos de clases `red-*`) y los tokens `destructivo`/`destructivo-bg` existen en `tailwind.config.js` pero **ningún componente los usa todavía**. **Propuesta:** el aviso usa los tokens `destructivo`/`destructivo-bg` (DESIGN.md) y el botón usa `.btn-destructivo` (regla de la skill), sin inventar un rojo nuevo. Si se prefiere un botón rojo como el de `AveBajaModal`, es una decisión de diseño que conviene tomar para todo el sistema, no solo aquí.
5. **Los datos de demostración y de prueba recientes no se pueden quitar con este botón** (el piso es 3 meses). Los 3 logs reales de las pruebas manuales del 10-11-2026 tampoco. Si se quieren quitar, se hace aparte, con autorización explícita.

6. **Selector de rangos (propuesta del diseñador, adoptada por delegación del usuario):** tres píldoras con `RangeToggle` (3, 6 y 12 meses), no checkbox ni lista desplegable; ver el punto 3b del diseño. `RangeToggle` es táctil pequeño (`text-xs`, `px-3 py-1`), poco para una acción destructiva, así que gana una prop opcional `size` (`'sm'` por defecto, que conserva el aspecto actual, y `'md'` con `text-sm px-4 py-2`) usada solo en este modal. Es el único cambio a un componente global y es compatible hacia atrás (hoy solo lo usa Benchmark).

## Global Constraints

- **Backend** (`.agents/skills/avicola-backend`): controlador delgado, `FormRequest` para validar, servicio para la lógica, `DB::transaction`, JSON `{success, message, data}`, mensajes en español, rutas bajo `auth:sanctum`. No se agregan tablas ni migraciones. `mnt_funcion_log_agent_tools` conserva `restrictOnDelete()`: **siempre se borra primero la herramienta y luego el turno**.
- **Frontend** (`.agents/skills/avicola-frontend` + `DESIGN.md`): formularios con `FormField` (etiqueta sobre el campo, sin notch); botones del pie del modal **centrados** (`flex items-center justify-center gap-3`), `.btn-secundario` y `.btn-destructivo`; backdrop sin escala (lo da `BaseModal`); toda mutación con `ActionLoader` vía `useActionLoader`; avisos con `notify.success/warning/error` (nunca `alert()` ni `confirm()`); 422 con `notify.warning`; tokens `destructivo`/`destructivo-bg` y no clases `red-*`; iconos Lucide; sin emojis; reutilizar `common/`. Los componentes establecidos (`BaseConfirmModal`, `FilterBar`, `DataTable`, `AppHeader`) **no se modifican**. Única excepción: `RangeToggle` (de este mismo módulo) gana la prop opcional `size`, sin cambiar su aspecto actual.
- **No dañar lo existente:** el controlador `AsistenteBenchmarkController` y sus endpoints de lectura no cambian (siguen siendo solo lectura). Antes de empezar: línea base de la suite aislada y `npm run build` en verde.
- **Pruebas backend SIEMPRE aisladas:** `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test [--filter=X]` en `avicola-backend-api/` (un `php artisan test` a secas vacía la BD de desarrollo). En adelante `$TEST`.
- **Base de datos de desarrollo:** solo `SELECT`. El borrado real se prueba únicamente en SQLite. Para la verificación en el navegador hace falta insertar turnos antiguos de demostración: **pedir autorización explícita al usuario** antes de escribir en la BD de desarrollo y borrarlos después.
- Pint solo sobre los archivos creados o modificados. **No commitear** hasta que el usuario lo ordene, y **sin línea de coautor**.

## Review Focus

1. **Valores de `meses` fuera de la lista** (`0`, `1`, `2`, `4`, `13`, `-3`, `6.5`, `abc`, vacío, ausente): nunca deben borrar nada; 422 en la API (vista previa y borrado) y excepción en el servicio si se llama directo.
2. **Borde del corte:** una interacción 1 minuto más vieja que el corte se borra y una 1 minuto más nueva se conserva, para 3, 6 y 12 meses; el corte usa meses calendario sin desborde (`2026-08-31` menos 6 meses es `2026-02-28`, no marzo) y la zona de negocio (`America/El_Salvador`) comparada en UTC.
3. **Orden de borrado y lotes:** con muchas herramientas por turno y lotes pequeños no debe fallar por la clave foránea ni dejar herramientas huérfanas; repetir la limpieza devuelve ceros (idempotente) y dos limpiezas simultáneas no fallan.
4. **Vista previa desfasada en la pantalla:** si el usuario cambia de opción rápido, solo la última respuesta puede mostrarse, y el botón de confirmar permanece deshabilitado mientras la vista previa no corresponda a la opción elegida (si no, se podría confirmar "6 meses" viendo el conteo de "1 año").
5. **Después de borrar:** la tabla puede quedar en una página que ya no existe (volver a la 1), las estadísticas se recargan y el rastro de `laravel.log` no contiene texto de mensajes de operarios.

## Estructura de archivos

**Backend (`avicola-backend-api/`)**
- Crear `app/Services/Asistente/Benchmark/DepuracionLogsAgenteService.php`
- Crear `app/Http/Controllers/Api/V1/DepuracionLogsAgenteController.php`
- Crear `app/Http/Requests/Asistente/DepuracionLogsAgenteRequest.php`
- Modificar `routes/api.php` (dos rutas nuevas dentro del prefijo `asistente/benchmark`)
- Tests: `tests/Feature/DepuracionLogsAgenteTest.php`

**Frontend (`avicola-frontend/src/`)**
- Crear `components/benchmark/LimpiezaHistoricoModal.vue`
- Modificar `services/benchmark.js`, `stores/benchmark.js`, `utils/benchmark.js`, `views/BenchmarkView.vue`, `components/common/RangeToggle.vue` (solo la prop opcional `size`)

**Docs:** `avicola-backend-api/README.md` (§5.9), `docs/aviacore-benchmark-guardrails.md`, `docs/aviacore-arquitectura-harness.md` (§10.2), `avicola-frontend/README.md` (§6.8 y deuda), README raíz (Etapa 8.1) y una nota en la spec.

---

### Task 1: Servicio de depuración

**Files:** Create `app/Services/Asistente/Benchmark/DepuracionLogsAgenteService.php`. Test: `tests/Feature/DepuracionLogsAgenteTest.php`.

**Interfaces:**
- Consumes: modelos `FuncionLogAgent` y `FuncionLogAgentTool`; `config('avicola.timezone')`.
- Produces:
  - `DepuracionLogsAgenteService::MESES_PERMITIDOS = [3, 6, 12]`.
  - `vistaPrevia(int $meses): array` → `['meses' => int, 'corte' => 'd-m-Y', 'turnos' => int, 'tools' => int, 'conservados' => int]` (`conservados` = turnos que quedarían). No escribe nada.
  - `depurar(int $meses, int $tamanoLote = 500): array` → mismo formato, con `turnos` y `tools` = lo realmente eliminado.
  - Ambos lanzan `InvalidArgumentException` si `$meses` no está en `MESES_PERMITIDOS`, antes de consultar nada.
- Reglas: corte = `CarbonImmutable::now(zona)->subMonthsNoOverflow($meses)` convertido a UTC; se elimina `created_at < corte`. Un único método privado calcula el corte y lo usan `vistaPrevia` y `depurar`. Cada lote en `DB::transaction`: primero `mnt_funcion_log_agent_tools` de esos ids y luego `mnt_funcion_log_agent`; se repite hasta que no queden turnos que cumplan.

- [x] **Step 1: Tests** (con `Carbon::setTestNow` y `config(['avicola.timezone' => 'America/El_Salvador'])`):
  - `test_rechaza_meses_fuera_de_la_lista_sin_consultar_ni_borrar` (valores del Review Focus 1; todos los logs intactos).
  - `test_borra_solo_lo_anterior_al_corte_con_sus_herramientas` (turnos de hace 13 meses con 2 tools y de hace 2 meses con 1 tool; `depurar(12)` → queda lo reciente y sus tools; el retorno cuenta 1 turno y 2 tools).
  - `test_el_borde_del_corte_es_exacto_para_3_6_y_12_meses` (1 minuto más viejo se borra, 1 minuto más nuevo se conserva; tres casos).
  - `test_el_corte_no_desborda_a_fin_de_mes` (`setTestNow('2026-08-31')`, 6 meses → corte `28-02-2026`).
  - `test_la_vista_previa_no_borra_y_coincide_con_el_borrado` (mismos `turnos` y `tools` en ambas; la BD igual tras la vista previa).
  - `test_conservados_es_lo_que_queda_despues_de_depurar`.
  - `test_borra_por_lotes_sin_dejar_herramientas_huerfanas` (lote 2, 5 turnos viejos con 3 tools cada uno → 0 turnos y 0 tools viejas, sin error de clave foránea).
  - `test_es_idempotente` (la segunda llamada devuelve ceros).
  - `test_no_toca_otras_tablas` (conteos de `users` y `mnt_gastos` iguales).
  - `test_con_la_bd_vacia_devuelve_ceros`.
- [x] **Step 2:** `$TEST --filter=DepuracionLogsAgenteTest` → FALLA (clase inexistente).
- [x] **Step 3:** Implementar el servicio con la firma y reglas de arriba.
- [x] **Step 4:** `$TEST --filter=DepuracionLogsAgenteTest` → PASA.

### Task 2: Endpoints de vista previa y borrado

**Files:** Create `DepuracionLogsAgenteController.php`, `DepuracionLogsAgenteRequest.php`. Modify `routes/api.php`. Test: ampliar `tests/Feature/DepuracionLogsAgenteTest.php`.

**Interfaces:**
- Consumes: Task 1.
- Produces (bajo `auth:sanctum`, dentro del prefijo existente `asistente/benchmark`):
  - `GET /asistente/benchmark/depuracion?meses=` → `{success:true, data:{meses, corte, turnos, tools, conservados}}`.
  - `DELETE /asistente/benchmark/depuracion?meses=` → `{success:true, message, data:{...}}`. `message`: "Se eliminaron N interacciones del historial." o, si N es 0, "No había interacciones anteriores a {corte}."
  - `DepuracionLogsAgenteRequest::rules()`: `meses` → `required`, `integer`, `in:3,6,12` (la lista sale de `DepuracionLogsAgenteService::MESES_PERMITIDOS`); mensajes en español.
  - `DepuracionLogsAgenteController::vistaPrevia(DepuracionLogsAgenteRequest)` y `::destroy(DepuracionLogsAgenteRequest)`: delgados; `destroy` escribe una línea `Log::warning('[AviaCore] Limpieza de histórico del Benchmark', [...])` con `id_usuario` del solicitante, `meses`, `corte`, `turnos` y `tools`, **sin contenido de mensajes**.

- [x] **Step 1: Tests:** `test_los_endpoints_requieren_autenticacion` (401 en GET y DELETE), `test_validacion_rechaza_meses_invalidos_con_422_y_no_borra` (todos los valores del Review Focus 1, en GET y en DELETE), `test_la_vista_previa_devuelve_conteos_y_no_borra`, `test_delete_elimina_lo_antiguo_conserva_lo_reciente_y_responde_el_formato_estandar`, `test_delete_sin_registros_antiguos_responde_200_con_ceros_y_mensaje`, `test_la_limpieza_deja_rastro_con_el_usuario_y_sin_contenido_de_mensajes` (`Log::spy()`: advertencia con `id_usuario`, `meses` y conteos; ninguna llamada contiene el texto de un `mensaje_usuario`), `test_los_endpoints_de_lectura_del_benchmark_siguen_igual` (estadísticas, logs y detalle responden como antes).
- [x] **Step 2:** `$TEST --filter=DepuracionLogsAgenteTest` → FALLAN los nuevos.
- [x] **Step 3:** Implementar request, controlador y rutas (el prefijo `asistente/benchmark` ya agrupa las rutas de lectura; las nuevas se agregan sin cambiar las existentes).
- [x] **Step 4:** `$TEST --filter=DepuracionLogsAgenteTest` → PASA. Luego la suite completa aislada en verde.

### Task 3: Cliente HTTP, store y opciones

**Files:** Modify `services/benchmark.js`, `stores/benchmark.js`, `utils/benchmark.js`.

**Interfaces:**
- Produces:
  - `getVistaPreviaDepuracion(meses)` → `GET /asistente/benchmark/depuracion` (devuelve `response.data.data`); `depurarLogs(meses)` → `DELETE` con `params: { meses }` (devuelve el cuerpo completo, con `message` y `data`).
  - En `utils/benchmark.js`: `MESES_DEPURACION = [3, 6, 12]` y `MESES_DEPURACION_DEFECTO = 12` (la opción que menos borra). Los valores deben coincidir con `MESES_PERMITIDOS`: el backend es la autoridad y rechaza lo demás. `RangeToggle` los entrega como número, así que no hay conversión de texto.
  - `RangeToggle.vue`: prop opcional `size: { type: String, default: 'sm', validator: (v) => ['sm', 'md'].includes(v) }`. Con `'sm'` el marcado y las clases actuales no cambian; con `'md'` los botones usan `px-4 py-2 text-sm` y el contenedor `p-1` (clases completas, no interpoladas, para que Tailwind no las elimine).
  - En el store: estado `vistaPreviaDepuracion` (`null` o `{meses, corte, turnos, tools, conservados}`) y `loadingVistaPrevia`; acciones `fetchVistaPreviaDepuracion(meses)` (solo la última petición escribe en el estado, con el mismo contador que ya usan `fetchLogs` y `fetchDetalle`), `limpiarVistaPreviaDepuracion()` (invalida las peticiones en vuelo) y `depurarLogs(meses)` (llama al servicio, luego pone `filters.page = 1`, recarga `fetchLogs()` y `fetchEstadisticas()` y devuelve el cuerpo de la respuesta).

- [x] **Step 1:** Implementar servicio, opciones y store.
- [x] **Step 2:** `npm run build` en `avicola-frontend/` → compila sin errores. (El frontend no tiene corredor de pruebas: se verifica con el build y en el navegador, y el README ya lo registra como deuda.)

### Task 4: Modal y botón en la pantalla

**Files:** Create `components/benchmark/LimpiezaHistoricoModal.vue`. Modify `views/BenchmarkView.vue`.

**Interfaces:**
- Consumes: Task 3; `BaseModal`, `RangeToggle`, `LoadingState` (o `Loader2`), `useActionLoader`, `useNotificationStore`.
- Produces:
  - `LimpiezaHistoricoModal` — props `isOpen: Boolean`; emite `close` y `cleaned`. Título **"Limpieza de histórico"**, `size="md"`. Contenido: aviso con `bg-destructivo-bg` y texto `text-destructivo` (icono `Eraser`), `RangeToggle` con `:options="MESES_DEPURACION"`, `unit-label="meses"`, `size="md"`, `aria-label="Eliminar las interacciones anteriores a"` y `:loading="isSubmitting"` (tres píldoras excluyentes; valor inicial `12`), bajo una etiqueta de campo "Eliminar las anteriores a" (`text-xs font-bold uppercase tracking-wider`, como `FormField`), y un bloque `aria-live="polite"` con la vista previa ("Se eliminarán N interacciones (M herramientas) anteriores al {corte}. Se conservan las de los últimos {meses} meses.", o "No hay interacciones anteriores al {corte}." o "Calculando…"). Pie centrado: `Cancelar` (`.btn-secundario`) y `Eliminar N registros` (`.btn-destructivo`, con `Loader2` mientras envía).
  - Reglas: al abrir y al cambiar de opción se pide la vista previa; el botón de confirmar está deshabilitado si la vista previa carga, falló, tiene 0 turnos o **no corresponde a la opción elegida**; mientras envía no se puede cerrar (`:closable="!isSubmitting"`) ni cambiar la opción. Al confirmar: `run('Eliminando historial...', () => store.depurarLogs(meses))`, `notify.success(message)`, emite `cleaned` y `close`. Errores: 422 → `notify.warning(mensaje del servidor)`, otro → `notify.error('No se pudo limpiar el historial.')`; el modal sigue abierto. Al cerrar se llama a `limpiarVistaPreviaDepuracion()`.
  - `BenchmarkView`: crear `provideActionLoader()` y dibujar un único `<ActionLoader :is-open="isSubmitting" :message="message" />`; en la barra de filtros de la pestaña Logs pasar `action-label="Limpiar historial"` y `:action-icon="Eraser"` a `FilterBar` (que ya lo soporta) y `@action` abre el modal; `@cleaned` no necesita más porque el store ya recarga logs y estadísticas.

- [x] **Step 1:** Implementar el modal y el cableado de la vista (sin tocar `FilterBar` ni `BaseModal`).
- [x] **Step 2:** `npm run build` → sin errores.
- [x] **Step 3: Verificación en el navegador** (servidor de desarrollo en el puerto 5173, que es el único que admite el CORS del backend): el botón aparece solo en la pestaña Logs junto a Buscar y Limpiar; el modal abre con la píldora "12 meses" seleccionada y las tres se ven a la vez (sin lista desplegable ni checkbox); cada clic en una píldora actualiza el conteo; con la BD de desarrollo actual (solo logs recientes) la vista previa dice "No hay interacciones anteriores…" y el botón de confirmar queda deshabilitado; cambiar rápido de opción no deja un conteo desfasado; el modal no se cierra mientras envía; vista móvil sin desbordes. Para ver un borrado real hay que **pedir autorización** al usuario para insertar 3 o 4 turnos antiguos de demostración (con `uuid_conversacion` de `BenchmarkDemoSeeder::UUIDS_DEMO` y `created_at` de hace 4, 7 y 13 meses) y eliminarlos al terminar; comprobar el aviso de éxito, la tabla recargada en la página 1 y las estadísticas.

### Task 5: Documentación y verificación final

- [x] **Step 1:** Suite completa aislada en verde y `npm run build` limpio.
- [x] **Step 2:** Pint solo sobre los archivos PHP creados o modificados.
- [x] **Step 3:** Documentar: `avicola-backend-api/README.md` §5.9 (la API deja de ser "solo lectura": agregar los dos endpoints, la lista cerrada de meses y el rastro; quitar "sin purga por ahora"), `docs/aviacore-benchmark-guardrails.md` (§4 API y §8 pendientes), `docs/aviacore-arquitectura-harness.md` §10.2, `avicola-frontend/README.md` §6.8 (el modal y el botón) y README raíz (Etapa 8.1: la depuración manual pasa de "pendiente" a hecha; la automática queda como fase 2). Agregar una nota en la spec indicando que la purga manual se implementó.
- [x] **Step 4:** Resumen al usuario con lo creado y lo pendiente. Sin commits hasta que lo ordene.

## Fase 2 opcional (NO incluida en este plan)

Depuración **automática** programada: un comando `avicola:depurar-logs-agente` que reutilice `DepuracionLogsAgenteService`, programado a diario. Hoy el contenedor `api` solo ejecuta `php artisan serve` y nada corre el planificador de Laravel, así que además haría falta un proceso `schedule:work` (servicio extra en `docker-compose.yml`) o un cron. Se planifica solo si el usuario lo pide, y el servicio de este plan está pensado para soportarlo sin cambios (aceptaría un período configurable en lugar de la lista cerrada, con su propia validación).

## Self-Review (contra la propuesta y los estándares)

- **Cobertura:** botón en la barra de filtros (T4), modal pequeño con título "Limpieza de histórico" (T4), tres opciones de 1 año, 6 y 3 meses (T3, T4), no borrar lo actual (lista cerrada y piso de 3 meses en servidor, T1 y T2), vista previa (T1, T2, T4), estándares de cada repo (Global Constraints), documentación (T5).
- **Consistencia de nombres:** `vistaPrevia`/`depurar`/`MESES_PERMITIDOS` (T1) se usan en T2; `getVistaPreviaDepuracion`/`depurarLogs`/`MESES_DEPURACION` (T3) se usan en T4.
- **Sin alterar lo establecido:** no se modifican `BaseConfirmModal`, `FilterBar`, `AppHeader`, `DataTable` ni el controlador de lectura del Benchmark (solo `RangeToggle` gana la prop opcional `size`).
- **Proporción:** 5 tareas pequeñas; sin migraciones ni tablas nuevas.
