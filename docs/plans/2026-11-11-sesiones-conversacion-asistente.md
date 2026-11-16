# Sesiones de conversación del asistente AviaCore — Plan de Implementación (FUTURO)

**Estado:** Finalizado con éxito

> **REEMPLAZADO — no ejecutar.** Este plan lo sustituye `2026-11-11-historial-conversaciones-asistente.md` (con su spec `specs/2026-11-11-historial-conversaciones-asistente-design.md`). Partía de una sola conversación por usuario, un "limpiar" que borraba y una expiración de 12 h, lo que contradice lo pedido después: nada se borra, hay varias conversaciones y un historial. Se conserva solo como referencia de los datos de consumo de tokens de la sección "Hallazgos".

> **Estado original: planificado, NO implementado.** Las decisiones marcadas "Por confirmar" llevan una propuesta; el plan se puede ejecutar con ellas o ajustarlas antes.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la conversación con AviaCore sobreviva a recargar la página (se guarda por usuario en el servidor), que "Limpiar conversación" la borre de verdad, y que al modelo solo se le envíe el contexto reciente y útil para ahorrar tokens.

**Architecture:** Dos tablas nuevas (`mnt_conversaciones_asistente` y `mnt_mensajes_asistente`) guardan una conversación activa por usuario, con una bandera `rol` (`user` / `model`) por mensaje. `ConversacionAsistenteService` crea, expira, limpia y arma el contexto para Gemini (últimos N mensajes, dentro de una ventana de tiempo y solo los marcados `en_contexto`). El historial deja de venir del navegador: lo construye el servidor. La auditoría (`mnt_funcion_log_agent`) no se toca y sigue sin referenciar al usuario.

**Tech Stack:** Laravel (migraciones, servicio, FormRequest), PostgreSQL / SQLite en pruebas, Vue 3 + Pinia.

**Spec:** no hay documento de diseño aparte; este plan parte de la solicitud del usuario del 11-11-2026. Relacionado: `docs/superpowers/specs/2026-11-10-benchmark-guardrails-asistente-design.md` (§2 "Que el log identifique al usuario" es un anti-objetivo).

## Hallazgos que fundamentan el diseño (verificados en el código y en la base de desarrollo)

1. **La conversación vive solo en memoria** (`stores/asistente.js`, `mensajes: []`): recargar la página la pierde. Además `limpiarHistorial()` solo vacía el arreglo local.
2. **El historial lo manda el navegador y el servidor se lo cree.** El frontend envía los últimos 10 mensajes (`slice(-10)`) y `ChatRequest` solo valida que cada uno tenga `role` `user|model` y `parts`. Nada impide fabricar turnos del modelo llamando a la API directamente. Pasar el historial al servidor lo corrige.
3. **Bug de privacidad existente (por lectura del código, no probado en el navegador):** `authStore.logout()` borra el token pero no el store del asistente, y el widget solo se oculta con `v-if="authStore.isAuthenticated"`. Si el usuario A cierra sesión y el usuario B inicia sesión sin recargar la página, B vería los mensajes de A. Con persistencia en servidor esto importa más y se corrige en este plan.
4. **Lo que cuesta el historial (datos reales, 24 turnos con modelo):**
   - Base fija por llamada: **unos 2.060 tokens** (turnos sin historial y una sola llamada: 2.061 a 2.063). Es el prompt del sistema más la declaración de las 6 herramientas.
   - Con 10 mensajes de historial: unos **2.700 tokens**, es decir **unos 640 más (≈ 64 por mensaje)**. Y el historial se reenvía en cada llamada del turno: con 2 llamadas son ≈ 1.280 de más.
   - Pasar de 10 a 6 mensajes ahorra ≈ 250 tokens por llamada (≈ 10 % de un turno típico). **El ahorro es real pero modesto:** la base fija es entre el 75 % y el 100 % de la entrada. Reducirla (descripciones de herramientas más cortas, caché del proveedor) rinde más y queda fuera de este plan.
5. **Lo que se reenvía hoy es peor de lo necesario:** el historial incluye los saludos, los bloqueos de guards y los globos de error, que no aportan nada al modelo.
6. **Colisión con la confidencialidad ya acordada.** La bitácora de auditoría se diseñó sin ninguna referencia al usuario (spec §2, anti-objetivo). Una conversación persistida *tiene* que estar ligada a un usuario, y el texto de cada mensaje quedaría en dos tablas (la de conversaciones, con usuario, y la de auditoría, anónima). Ver la decisión 1.

## Decisiones por confirmar

1. **Privacidad (la importante).** Guardar conversaciones por usuario relaja lo que hoy es una garantía fuerte de la auditoría: quien tenga acceso a la base de datos podría correlacionar un registro de auditoría con un usuario por texto y hora, aunque no haya clave que los una. **Propuesta:** (a) la tabla de auditoría no gana ninguna columna ni clave hacia usuarios ni conversaciones; (b) su `uuid_conversacion` pasa a ser un seudónimo `HMAC-SHA256(uuid de la conversación, APP_KEY)` con formato uuid, de modo que nadie pueda unir ambas tablas por identificador sin la clave de la aplicación; (c) el Benchmark sigue sin mostrar usuarios y no lee las tablas nuevas; (d) la documentación deja de decir "anónimo" y dice "sin referencia al usuario". Es **seudonimización, no anonimato absoluto**. La alternativa que conserva el anonimato tal cual es guardar la conversación solo en el navegador (`sessionStorage`): no requiere tablas ni servidor, pero se pierde al cambiar de navegador o dispositivo, el historial sigue siendo manipulable por el cliente y el recorte por antigüedad dependería del navegador. El usuario pidió explícitamente tablas, así que el plan sigue esa vía.
2. **Una sola conversación activa por usuario** (sin lista de conversaciones anteriores). "Limpiar" la borra y la siguiente pregunta crea otra. Propuesta: sí; una lista de conversaciones es otra funcionalidad.
3. **Confirmación al limpiar.** Hoy el botón borra sin preguntar porque solo vaciaba la memoria. Ahora el borrado es permanente y las reglas del frontend piden confirmación para eliminar registros. Propuesta: agregar un `BaseConfirmModal`. Es un cambio de comportamiento de una función existente.
4. **Valores por defecto** (todos configurables por variable de entorno): contexto de **6 mensajes** (3 intercambios), ventana de **60 minutos**, la conversación expira tras **12 horas** sin actividad y se guardan como máximo **100 mensajes**.
5. **Borrar la conversación no borra la auditoría del Benchmark.** Los turnos auditados se conservan sin referencia al usuario hasta que se depuren con "Limpiar historial". Propuesta: dejarlo así (una auditoría que se borra sola no audita) y avisarlo en la ayuda del botón.
6. **Datos que se pierden:** si el usuario recarga mientras el asistente todavía responde, ese turno puede no guardarse (el turno se persiste cuando termina). Se acepta y se documenta.

## Global Constraints

- **Backend** (`.agents/skills/avicola-backend`): prefijo `mnt_`; clave `id`; foráneas `id_<entidad>`; `restrictOnDelete()` (por eso la limpieza **siempre borra primero los mensajes y luego la conversación**); controladores delgados, `FormRequest`, servicio, `DB::transaction`; JSON `{success, message, data}`; rutas bajo `auth:sanctum`; mensajes en español.
- **Aislamiento por usuario:** ningún endpoint recibe un identificador de conversación ni de usuario; siempre se opera sobre la conversación del usuario autenticado (anti-IDOR, igual que `$contextoFijo`).
- **La auditoría no cambia de esquema** y sigue sin columna de usuario. No se agrega ninguna clave entre `mnt_funcion_log_agent` y las tablas nuevas.
- **El orquestador conserva su contrato:** `procesarConversacion($mensaje, $historial, $contextoFijo, $uuidConversacion)` recibe el contexto ya armado; solo agrega una clave `resultado` al arreglo que retorna. Los guards no cambian.
- **Frontend** (`.agents/skills/avicola-frontend` + `DESIGN.md`): vistas y widget solo orquestan; HTTP en `services/`; estado en el store; `BaseConfirmModal` para eliminar; `notify.*` (nunca `alert()`/`confirm()`); `LoadingState` para cargas; reutilizar `common/`; no se modifican componentes globales.
- **Pruebas backend SIEMPRE aisladas:** `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test [--filter=X]` en `avicola-backend-api/`. En adelante `$TEST`. Línea base antes de empezar: suite completa y `npm run build` en verde (hoy 269 pruebas).
- **Base de datos de desarrollo con datos reales:** solo migraciones **aditivas** con `php artisan migrate` (primero `migrate:status` y `--pretend`); nunca `migrate:fresh`/`refresh`; conteos de `mnt_aves`, `mnt_gastos`, `mnt_registros_huevos`, `mnt_registros_alimentacion` y de las tablas del log iguales antes y después. Sin `DELETE` ni `INSERT` manuales en desarrollo sin autorización expresa.
- Pint solo sobre los archivos tocados. **No commitear hasta que el usuario lo ordene, sin línea de coautor**; el `push` lo hace el usuario.

## Review Focus

1. **Aislamiento entre usuarios:** el usuario A no ve, no borra ni hereda el contexto de la conversación del usuario B; el cliente no puede inyectar turnos propios (`historial` o `uuid_conversacion` enviados se ignoran).
2. **Contexto bien formado:** tras recortar por cantidad y por edad, el contexto **empieza con un mensaje del usuario** y alterna turnos completos (no se verificó si `gemini-3.1-flash-lite` lo exige, así que se garantiza por construcción); sin mensajes válidos el contexto es vacío, no un error; los turnos de saludo, bloqueo por guard y error nunca entran.
3. **Expiración y concurrencia:** una conversación vencida no se muestra ni se usa; dos peticiones simultáneas del mismo usuario que crean la conversación no fallan por la clave única de `id_usuario`; el tope de 100 mensajes borra los más viejos sin dejar al contexto empezando en un mensaje del modelo.
4. **Valores de configuración inválidos** (`0`, negativos, no numéricos): nunca deben producir un contexto sin límite ni dividir entre cero; se usan los valores por defecto.
5. **Frontend:** cerrar sesión e iniciar otra sin recargar no muestra mensajes ajenos; si falla la carga de la conversación el chat sigue usable; si falla el borrado, la conversación local NO se vacía; mientras carga la conversación no se puede enviar un mensaje.

## Estructura de archivos

**Backend (`avicola-backend-api/`)**
- Crear `database/migrations/2026_11_11_100000_create_mnt_conversaciones_asistente_table.php` y `..._100100_create_mnt_mensajes_asistente_table.php`
- Crear `app/Models/ConversacionAsistente.php`, `app/Models/MensajeAsistente.php`
- Crear `config/asistente.php`
- Crear `app/Services/Asistente/Conversacion/ConversacionAsistenteService.php`
- Crear `app/Http/Controllers/Api/V1/AsistenteConversacionController.php`
- Modificar `app/Http/Controllers/Api/V1/AsistenteController.php`, `app/Http/Requests/Asistente/ChatRequest.php`, `app/Services/Asistente/AsistenteOrquestadorService.php` (solo agrega `resultado` al retorno), `routes/api.php`
- Tests: `tests/Feature/ConversacionAsistenteTest.php`; ajustar `tests/Feature/BenchmarkOrquestadorTest.php` (la prueba `test_uuid_de_conversacion_se_guarda_y_se_valida` cambia de intención: el cliente ya no aporta el uuid)

**Frontend (`avicola-frontend/src/`)**
- Modificar `services/asistente.js`, `stores/asistente.js`, `stores/auth.js` (reiniciar el asistente al cerrar sesión), `components/asistente/AsistenteChatWidget.vue`

**Docs:** README del backend (§5.7 y §5.9), README del frontend (§6.7), `docs/aviacore-arquitectura-harness.md`, `docs/aviacore-benchmark-guardrails.md` (privacidad y `uuid_conversacion`), README raíz.

---

### Task 1: Tablas y modelos de conversación

**Files:** las 2 migraciones y 2 modelos de la estructura. Test: `tests/Feature/ConversacionAsistenteTest.php` (esquema).

**Interfaces:**
- Produces:
  - `mnt_conversaciones_asistente`: `id`; `id_usuario` (FK a `mnt_usuarios`, `restrictOnDelete()`, **único**); `uuid` (único, aleatorio); `ultima_actividad_at` (timestamp, índice); `timestamps`.
  - `mnt_mensajes_asistente`: `id`; `id_conversacion_asistente` (FK, `restrictOnDelete()`); `rol` (`string(10)`: `user` o `model`, el mismo vocabulario de Gemini y del frontend); `contenido` (text); `herramientas` (json nullable) y `sugerencias_modulos` (json nullable) para repintar las insignias y los atajos igual que antes de recargar; `es_error` (boolean, por defecto `false`); `en_contexto` (boolean, por defecto `true`); `created_at`. Índice compuesto `(id_conversacion_asistente, id)`.
  - `ConversacionAsistente` (relación `mensajes()` ordenada por `id`, `usuario()`), `MensajeAsistente` (`UPDATED_AT = null`; constantes `ROL_USUARIO = 'user'` y `ROL_MODELO = 'model'`; casts de json y boolean).
  - **No** hay ninguna clave entre estas tablas y `mnt_funcion_log_agent`.

- [ ] **Step 1: Tests:** `test_hay_una_sola_conversacion_por_usuario` (una segunda para el mismo `id_usuario` falla por la clave única), `test_los_mensajes_se_leen_en_orden_de_insercion_con_sus_banderas`, `test_no_se_puede_borrar_una_conversacion_con_mensajes` (la FK restringe), `test_las_tablas_de_auditoria_no_referencian_conversaciones_ni_usuarios` (`Schema::getColumnListing('mnt_funcion_log_agent')` no cambia: sin `id_usuario`, `id_conversacion*`).
- [ ] **Step 2:** `$TEST --filter=ConversacionAsistenteTest` → FALLA.
- [ ] **Step 3:** Migraciones y modelos.
- [ ] **Step 4:** `$TEST --filter=ConversacionAsistenteTest` → PASA.
- [ ] **Step 5: Migrar la base de desarrollo (aditivo, con datos reales):** `migrate:status` (solo las 2 nuevas pendientes), `migrate --pretend` (solo `create table`/índices/FK de las tablas nuevas), `migrate`, y conteos de las tablas de negocio y del log idénticos antes y después.

### Task 2: Configuración y servicio de conversación

**Files:** Create `config/asistente.php`, `app/Services/Asistente/Conversacion/ConversacionAsistenteService.php`. Test: ampliar `ConversacionAsistenteTest.php`.

**Interfaces:**
- Consumes: Task 1; `FuncionLogAgent::RESULTADO_*`.
- Produces:
  - `config('asistente.conversacion')`: `max_mensajes_contexto` (6), `max_edad_contexto_minutos` (60), `expira_horas` (12), `max_mensajes_guardados` (100), cada uno con su variable de entorno (`ASISTENTE_CONTEXTO_MENSAJES`, `ASISTENTE_CONTEXTO_MINUTOS`, `ASISTENTE_CONVERSACION_HORAS`, `ASISTENTE_MENSAJES_GUARDADOS`). El servicio lee cada valor con `max(1, …)` y cae en el defecto si no es numérico.
  - `ConversacionAsistenteService`:
    - `activa(int $idUsuario): ?ConversacionAsistente` — la conversación del usuario; si venció (`ultima_actividad_at` anterior a `expira_horas`) la borra (mensajes primero) y devuelve `null`.
    - `obtenerOCrear(int $idUsuario): ConversacionAsistente` — usa `activa()` y, si no hay, crea una con `uuid` nuevo; tolera la carrera de dos creaciones simultáneas (reintenta una vez ante la violación de la clave única).
    - `contexto(ConversacionAsistente $c): array` — formato Gemini `[['role' => 'user'|'model', 'parts' => [['text' => …]]]]`: mensajes con `en_contexto = true`, creados dentro de la ventana de `max_edad_contexto_minutos`, los últimos `max_mensajes_contexto` por `id`; si el primero es del modelo se descartan los iniciales hasta que empiece con `user`; vacío si no queda ninguno.
    - `registrarTurno(ConversacionAsistente $c, string $mensajeUsuario, string $textoModelo, string $resultado, array $herramientas = [], array $sugerencias = []): array` — en una `DB::transaction` inserta el mensaje del usuario y el del modelo (en ese orden), `es_error = ($resultado === error)`, `en_contexto = ($resultado === exitoso)` en **ambos** mensajes del turno (así saludos, bloqueos y errores no entran al contexto y los turnos siempre quedan completos), actualiza `ultima_actividad_at` y, si se supera `max_mensajes_guardados`, borra los más viejos de a turnos completos. Devuelve `['usuario' => MensajeAsistente, 'modelo' => MensajeAsistente]`.
    - `limpiar(int $idUsuario): int` — borra los mensajes y luego la conversación del usuario; idempotente; devuelve cuántos mensajes borró.
    - `uuidAuditoria(ConversacionAsistente $c): string` — seudónimo con formato uuid derivado de `hash_hmac('sha256', $c->uuid, config('app.key'))` (32 hex tomados del resultado, en grupos 8-4-4-4-12). Determinista, distinto del `uuid` de la conversación y no reversible sin la clave.

- [ ] **Step 1: Tests** (con `Carbon::setTestNow`): `test_el_contexto_toma_los_ultimos_n_mensajes_y_empieza_con_el_usuario` (con 10 mensajes y N = 6, 6 mensajes, el primero `user`; con N impar se descarta el mensaje inicial del modelo), `test_el_contexto_excluye_mensajes_fuera_de_la_ventana_de_tiempo_aunque_haya_menos_de_n` (borde: 1 minuto dentro y 1 minuto fuera), `test_los_turnos_de_saludo_bloqueo_y_error_no_entran_al_contexto` (ambos mensajes del turno con `en_contexto = false`), `test_sin_mensajes_validos_el_contexto_es_vacio`, `test_registrar_turno_guarda_ambos_mensajes_en_orden_con_herramientas_y_sugerencias`, `test_el_tope_de_mensajes_guardados_borra_turnos_completos_de_los_mas_viejos`, `test_una_conversacion_vencida_se_borra_y_no_se_devuelve` (borde de 12 h), `test_limpiar_borra_mensajes_y_conversacion_y_es_idempotente`, `test_las_conversaciones_de_dos_usuarios_no_se_mezclan`, `test_configuracion_invalida_cae_en_los_valores_por_defecto` (`0`, `-3`, `abc`), `test_el_uuid_de_auditoria_es_determinista_con_formato_uuid_y_distinto_del_uuid_de_la_conversacion`, `test_dos_conversaciones_tienen_uuid_de_auditoria_distintos`, `test_crear_dos_veces_para_el_mismo_usuario_no_falla_por_la_clave_unica`.
- [ ] **Step 2:** `$TEST --filter=ConversacionAsistenteTest` → FALLAN los nuevos.
- [ ] **Step 3:** Implementar la configuración y el servicio con las firmas de arriba.
- [ ] **Step 4:** `$TEST --filter=ConversacionAsistenteTest` → PASA.

### Task 3: Endpoints y chat con historial del servidor

**Files:** Create `AsistenteConversacionController.php`. Modify `AsistenteController.php`, `ChatRequest.php`, `AsistenteOrquestadorService.php`, `routes/api.php`. Test: ampliar `ConversacionAsistenteTest.php`; ajustar `BenchmarkOrquestadorTest.php`.

**Interfaces:**
- Consumes: Task 2.
- Produces:
  - `GET /asistente/conversacion` → `{success:true, data:{mensajes:[{id, rol, contenido, herramientas, sugerencias_modulos, es_error, created_at}]}}` (lista vacía si no hay conversación vigente). **No** expone el `uuid` ni el id del usuario.
  - `DELETE /asistente/conversacion` → `{success:true, message:'Conversación eliminada.'}` (200 aunque no hubiera). Ambos sin parámetros: operan solo sobre el usuario autenticado.
  - `POST /asistente/chat`: ya no usa `historial` ni `uuid_conversacion` del cliente (se quitan de las reglas de `ChatRequest`; si llegan, se ignoran). Flujo: `obtenerOCrear` → `contexto` → `procesarConversacion($mensaje, $contexto, $contextoFijo, uuidAuditoria($c))` → `registrarTurno(..., resultado: $resultado['resultado'], …)` → respuesta JSON **sin** la clave `resultado`. Ante una excepción: `registrarTurno` con `resultado = error` y el texto del 503 actual, y se conserva la respuesta 503 y el `Log::error` del controlador.
  - `AsistenteOrquestadorService::procesarConversacion` agrega la clave `resultado` (el mismo valor `exitoso|fast_path|bloqueado_guard` que ya guarda la auditoría) al arreglo que retorna; el resto del contrato no cambia.
  - `turnos_historial` de la auditoría pasa a ser la cantidad de mensajes de contexto realmente enviados (ya es `count($historial)`).

- [ ] **Step 1: Tests:** `test_get_devuelve_la_conversacion_del_usuario_en_orden_con_sus_banderas`, `test_get_sin_conversacion_devuelve_lista_vacia`, `test_get_y_delete_requieren_autenticacion` (401), `test_un_usuario_no_ve_ni_borra_la_conversacion_de_otro`, `test_delete_borra_la_conversacion_y_el_siguiente_mensaje_crea_otra`, `test_el_chat_guarda_el_turno_y_el_siguiente_envia_el_contexto_al_modelo` (`Http::fake` + `Http::assertSent` con el texto del turno anterior en `contents`), `test_el_historial_enviado_por_el_cliente_se_ignora` (un `historial` con un turno de modelo fabricado y 25 elementos: no da 422 y no aparece en lo que se envía a Gemini), `test_un_uuid_conversacion_enviado_por_el_cliente_se_ignora`, `test_saludo_y_bloqueo_se_guardan_pero_no_entran_al_contexto_del_siguiente_turno`, `test_un_error_del_proveedor_guarda_el_turno_como_error_y_responde_503`, `test_la_auditoria_usa_un_uuid_estable_entre_turnos_y_nuevo_tras_limpiar`, `test_la_auditoria_no_guarda_el_uuid_de_la_conversacion_ni_referencia_al_usuario` (el `uuid_conversacion` del log ≠ `uuid` de la conversación y no hay columna de usuario), `test_las_respuestas_del_benchmark_no_incluyen_datos_de_conversaciones`, `test_la_respuesta_del_chat_no_expone_la_clave_resultado`, `test_el_contexto_respeta_el_limite_configurado` (con `config(['asistente.conversacion.max_mensajes_contexto' => 2])`). En `BenchmarkOrquestadorTest`, reemplazar `test_uuid_de_conversacion_se_guarda_y_se_valida` por una prueba que compruebe que el `uuid_conversacion` del log es el derivado por el servidor.
- [ ] **Step 2:** `$TEST --filter="ConversacionAsistenteTest|BenchmarkOrquestadorTest|AsistenteTest"` → FALLAN los nuevos.
- [ ] **Step 3:** Implementar controlador, rutas y ajustes; verificar con una búsqueda que ninguna prueba existente compare el arreglo completo que retorna el orquestador (las revisadas leen claves sueltas).
- [ ] **Step 4:** mismo comando → PASA, y luego la suite completa aislada en verde.

### Task 4: Servicio y store del frontend

**Files:** Modify `services/asistente.js`, `stores/asistente.js`, `stores/auth.js`.

**Interfaces:**
- Consumes: Task 3.
- Produces:
  - `services/asistente.js`: `obtenerConversacion()` (devuelve `data.mensajes`), `limpiarConversacion()`, y `enviarMensaje({ mensaje })` sin `historial` ni `uuid_conversacion`.
  - `stores/asistente.js`: se eliminan `uuidConversacion` y `nuevoUuidConversacion`; se agrega `cargandoConversacion`. Acciones: `cargarConversacion()` (una vez por sesión; mapea cada mensaje del servidor a `{ id: 'srv-<id>', role: rol, text: contenido, timestamp: hh:mm local, herramientas, sugerenciasModulos, esError }`; solo la última petición escribe en el estado; si falla deja el chat vacío y usable, sin notificar), `limpiarHistorial()` ahora **asíncrona**: llama al backend y **solo si responde bien** vacía `mensajes` (si falla, notifica con `notify.error` y deja la conversación intacta; devuelve `true`/`false`), y `reiniciar()` (vacía mensajes, invalida cargas en curso y restablece banderas).
  - `stores/auth.js`: en el `finally` de `logout()` llama a `useAsistenteStore().reiniciar()` (cubre también el cierre por `fetchCurrentUser` fallido).

- [ ] **Step 1:** Implementar servicio y store.
- [ ] **Step 2:** `npm run build` en `avicola-frontend/` → sin errores. (El frontend no tiene corredor de pruebas; se verifica con el build y en el navegador en la Task 5.)

### Task 5: Widget y verificación en el navegador

**Files:** Modify `components/asistente/AsistenteChatWidget.vue`.

**Interfaces:**
- Consumes: Task 4; `BaseConfirmModal`, `LoadingState`, `useNotificationStore`.
- Produces: al montarse llama a `cargarConversacion()`; mientras `cargandoConversacion` muestra `LoadingState` (mensaje "Cargando conversación...") en el área de mensajes y deshabilita el campo y el botón de enviar; tras cargar hace scroll al último mensaje. El botón "Limpiar conversación" abre un `BaseConfirmModal` local (título "Limpiar conversación", pregunta "¿Deseas eliminar esta conversación?", descripción "Se borrarán los mensajes guardados de tu conversación con AviaCore. Esta acción no se puede deshacer. El registro de auditoría del Benchmark no se ve afectado.", `confirm-text="Sí, limpiar"`, `is-destructive`, `:loading` mientras borra) y, al confirmar, `limpiarHistorial()` con `notify.success('Conversación eliminada.')` o el aviso de error del store. El widget no depende de `provideActionLoader` (vive en `App.vue`).

- [ ] **Step 1:** Implementar el widget.
- [ ] **Step 2:** `npm run build` → sin errores.
- [ ] **Step 3: Verificación en el navegador** (servidor de desarrollo en el puerto 5173, el único que admite el CORS del backend; la inserción de datos solo vía el propio chat): enviar 2 preguntas y recargar → la conversación reaparece con sus insignias de herramientas; "Limpiar" pide confirmación, cancelar no borra, confirmar borra y al recargar queda vacío; cerrar sesión e iniciar con otro usuario (si existe; si no, comprobar con el mismo que el widget empieza vacío tras iniciar sesión sin recargar) no muestra mensajes ajenos; escribir mientras carga está deshabilitado; un saludo ("hola") reaparece tras recargar pero no se envía como contexto (verificar en Benchmark que `turnos_historial` del turno siguiente no lo cuenta); un error del proveedor reaparece como globo de error; en Benchmark los turnos nuevos aparecen sin usuario y con un `uuid_conversacion` distinto del de la conversación.

### Task 6: Documentación y verificación final

- [ ] **Step 1:** Suite completa aislada en verde y `npm run build` limpio; conteos de las tablas de negocio iguales a la línea base.
- [ ] **Step 2:** Pint solo sobre los archivos PHP creados o modificados.
- [ ] **Step 3:** Documentar: README del backend (§5.7: `chat` sin `historial` ni `uuid_conversacion`, endpoints `conversacion`, configuración `asistente.php`; §5.9: la auditoría sigue sin columna de usuario y su `uuid_conversacion` es un seudónimo derivado), README del frontend (§6.7: carga, limpieza con confirmación, reinicio al cerrar sesión), `docs/aviacore-arquitectura-harness.md` (estructura de carpetas, flujo y tablas nuevas), `docs/aviacore-benchmark-guardrails.md` (§5 Privacidad: de "anónimo" a "sin referencia al usuario, con seudónimo derivado", y `uuid_conversacion`), README raíz (etapa). Dejar constancia de la decisión 1.
- [ ] **Step 4:** Resumen al usuario con lo creado, los valores por defecto aplicados y lo pendiente. Sin commits hasta que lo ordene.

## Fuera de alcance (ideas para después)

- Reducir la base fija de ≈ 2.060 tokens por llamada (descripciones de herramientas más cortas, caché implícita o explícita del proveedor): es la palanca de ahorro más grande.
- Separador visual en el chat ("el asistente ya no recuerda los mensajes anteriores a esta línea") para los mensajes que quedaron fuera de la ventana de 60 minutos.
- Varias conversaciones por usuario con un listado.
- Presupuesto por caracteres o tokens reales del contexto (hoy se limita por cantidad y antigüedad).
- Depuración automática de conversaciones abandonadas: hoy se limpian cuando el usuario vuelve o pulsa "Limpiar", y su volumen está acotado a una conversación y 100 mensajes por usuario.

## Self-Review

- **Cobertura:** persistir tras recargar (T1, T2, T3, T5), borrado real (T2, T3, T5), recorte por cantidad y antigüedad con exclusión de turnos inútiles (T2), nuevas tablas con bandera de rol (T1), convivencia con las tablas de auditoría sin unirlas (T1, T2, T3), cierre de sesión (T4).
- **Consistencia de nombres:** `obtenerOCrear`, `contexto`, `registrarTurno`, `limpiar` y `uuidAuditoria` (T2) se usan en T3; `obtenerConversacion` y `limpiarConversacion` (T4) se usan en T5; la clave `resultado` del orquestador (T3) alimenta a `registrarTurno` (T2).
- **Lo que cambia de lo ya construido:** `ChatRequest` (se quitan 2 campos), una prueba de `BenchmarkOrquestadorTest`, el retorno del orquestador (una clave más) y `authStore.logout`. El esquema de la auditoría y el Benchmark no cambian.
- **Proporción:** 6 tareas; dos tablas, un servicio, un controlador pequeño y cambios acotados en el frontend.
