# Conversaciones persistentes e historial del asistente AviaCore — Plan de Implementación (FUTURO)

**Estado:** Finalizado con éxito

> **Estado: planificado, NO implementado.** Reemplaza a `2026-11-11-sesiones-conversacion-asistente.md` (una sola conversación por usuario, "limpiar" que borraba, ventana de 60 min y expiración de 12 h), que contradice lo que pidió el usuario: nada se borra, hay varias conversaciones y un historial.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la conversación con AviaCore sobreviva a recargar la página, que el usuario pueda retomar conversaciones anteriores desde un modal de historial (sin borrar nunca nada, solo cambiar su estado), que cada conversación tenga un nombre automático, y que al modelo solo se le envíen los últimos mensajes útiles.

**Architecture:** Dos tablas nuevas (`mnt_conversaciones_asistente`, `mnt_mensajes_asistente`) guardan varias conversaciones por usuario, cada una con UUID, nombre y estado (`activa`/`eliminada`), y mensajes con la bandera `rol`. `ConversacionAsistenteService` resuelve **siempre contra el usuario autenticado**, arma el contexto (últimos 6 mensajes `exitoso`) y guarda cada turno; el historial deja de venir del navegador. `TituloConversacionService` nombra la conversación en una llamada aparte y corta a Gemini, con respaldo garantizado. En el frontend el store guarda el UUID en `localStorage` y el widget gana el botón Historial con su modal. La auditoría del Benchmark no cambia de esquema y recibe un seudónimo del UUID.

**Tech Stack:** Laravel (migraciones, servicios, `FormRequest`), PostgreSQL / SQLite en pruebas, Gemini; Vue 3 + Pinia + Tailwind + Lucide.

**Spec:** `docs/superpowers/specs/2026-11-11-historial-conversaciones-asistente-design.md` (decisiones D1 a D10). Los puntos que el spec deja al constructor (sección 10) los resuelve quien implemente y los registra en el ledger.

## Global Constraints

- **Nada se borra (D2):** ninguna operación hace `DELETE` físico de conversaciones ni de mensajes; "eliminar" pone `estado = eliminada` y `eliminada_at`. Ningún código nuevo borra filas de las dos tablas.
- **Aislamiento (anti-IDOR, D3):** todo acceso a una conversación se resuelve con `(uuid, id_usuario autenticado, estado = activa)`; un UUID con formato inválido, ajeno, inexistente o eliminado es indistinguible: **404 con el mismo mensaje**. Ningún endpoint recibe ni devuelve ids de usuario.
- **Backend** (`.agents/skills/avicola-backend`): prefijo `mnt_`, clave `id`, foráneas `id_<entidad>`, `restrictOnDelete()`, controladores delgados + `FormRequest` + servicio, `DB::transaction`, JSON `{success, message, data}`, rutas bajo `auth:sanctum`, mensajes en español.
- **La capa de conversación nunca rompe el chat:** si buscar, crear o guardar la conversación lanza una excepción, se registra en `laravel.log` y el chat responde igual (sin persistir, con `uuid_conversacion: null`). Nombrar tampoco puede romper nada.
- **Auditoría (D5):** `mnt_funcion_log_agent` y `mnt_funcion_log_agent_tools` **no cambian de esquema** y siguen sin columna de usuario ni clave hacia las tablas nuevas. Su `uuid_conversacion` pasa a ser `HMAC-SHA256(uuid, APP_KEY)` con formato UUID. El Benchmark no lee las tablas nuevas. Cambia el texto "anónimo" por "sin referencia al usuario" en las docs.
- **Contrato del orquestador:** `procesarConversacion($mensaje, $historial, $contextoFijo, $uuidConversacion)` no cambia de firma; solo agrega la clave `resultado` (`exitoso|fast_path|bloqueado_guard`) al arreglo que retorna. Los guardrails no cambian.
- **Frontend** (`.agents/skills/avicola-frontend` + `DESIGN.md`): el widget y los modales orquestan; HTTP en `services/`, estado en el store; `BaseModal` (botones del pie centrados, backdrop sin escala), `BaseConfirmModal` para eliminar, `FormField` en el formulario de renombrar, `RowActionButton`, `EmptyState`, `LoadingState`, `notify.*` (nunca `alert()` ni `confirm()`); tokens bosque/salvia/crema/verdoso, sin tarjetas anidadas con sombras, íconos Lucide (`History` para el historial, el mismo de Inventario de Aves; `MessagesSquare` para cada fila; `Pencil` y `Trash2`), sin emojis. **No se modifica ningún componente global.** Como la imagen de referencia es un tema oscuro con acentos azules, se conserva su **estructura**, no sus colores (D9).
- **Pruebas backend SIEMPRE aisladas:** `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test [--filter=X]` en `avicola-backend-api/` (un `php artisan test` a secas vacía la base de desarrollo). En adelante `$TEST`. **Línea base antes de empezar:** suite completa y `npm run build` en verde (hoy 278 pruebas).
- **Base de datos de desarrollo (datos reales):** solo migraciones **aditivas** con `php artisan migrate` (antes `migrate:status` y `--pretend`); nunca `migrate:fresh`/`refresh`; los conteos de `mnt_aves`, `mnt_gastos`, `mnt_registros_huevos`, `mnt_registros_alimentacion` y del log de auditoría idénticos antes y después. Sin `INSERT`/`DELETE` manuales.
- **La verificación en el navegador crea conversaciones reales de prueba** en la base de desarrollo (es el uso normal del chat) y, por la regla de no borrar, **quedarán en el historial del usuario de desarrollo**. Pedir autorización al usuario antes y avisarle al final.
- Pint solo sobre los archivos PHP creados o modificados. **No commitear hasta que el usuario lo ordene, sin línea de coautor; el `push` lo hace el usuario.**

## Review Focus

1. **Aislamiento:** el usuario A no lista, abre, renombra, elimina ni nombra la conversación de B (404); un `uuid_conversacion` ajeno enviado al chat crea una conversación nueva y **no** usa ni escribe en la ajena; dos usuarios en el mismo navegador no ven mensajes del otro.
2. **Peticiones en vuelo en el frontend:** pulsar Limpiar, abrir otra conversación o eliminar mientras hay un mensaje en curso no debe "resucitar" el UUID anterior ni mezclar la respuesta tardía en el chat nuevo; respuestas lentas de listados o aperturas no pisan a una posterior.
3. **Contexto bien formado:** tras recortar a 6 mensajes empieza con uno del usuario; los saludos, bloqueos y errores nunca entran; una conversación vieja retomada días después envía su contexto igual (sin ventana de tiempo); configuración inválida (`0`, negativa, no numérica) cae al valor por defecto y nunca produce un contexto sin límite.
4. **Nombre automático:** se intenta una sola vez por conversación aunque Gemini falle (503) o devuelva basura (vacío, largo, con `mnt_`); un nombre `manual` jamás se pisa; un saludo no lo dispara; se muestra solo con interpolación (nunca HTML).
5. **Conversación eliminada:** no se lista, no se abre y no recibe mensajes; si era la abierta, el chat se vacía y la clave de `localStorage` se borra; un UUID viejo en `localStorage` (eliminado o ajeno) deja el chat vacío y usable, no un error.

## Estructura de archivos

**Backend (`avicola-backend-api/`)**
- Crear `database/migrations/2026_11_12_100000_create_mnt_conversaciones_asistente_table.php` y `..._100100_create_mnt_mensajes_asistente_table.php`
- Crear `app/Models/ConversacionAsistente.php`, `app/Models/MensajeAsistente.php`
- Crear `config/asistente.php`
- Crear `app/Services/Asistente/Conversacion/ConversacionAsistenteService.php` y `TituloConversacionService.php`
- Crear `app/Http/Controllers/Api/V1/AsistenteConversacionController.php`
- Crear `app/Http/Requests/Asistente/RenombrarConversacionRequest.php`
- Modificar `AsistenteController.php`, `ChatRequest.php`, `AsistenteOrquestadorService.php` (una línea), `routes/api.php`
- Tests nuevos: `tests/Feature/ConversacionAsistenteTest.php`, `ConversacionAsistenteApiTest.php`, `TituloConversacionTest.php`; ajustar `BenchmarkOrquestadorTest.php`

**Frontend (`avicola-frontend/src/`)**
- Modificar `services/asistente.js`, `stores/asistente.js`, `stores/auth.js`, `components/asistente/AsistenteChatWidget.vue`
- Crear `components/asistente/HistorialConversacionesModal.vue` y `RenombrarConversacionModal.vue`
- Modificar `utils/benchmark.js` solo si hace falta reutilizar un formateador (no se espera)

**Docs:** README del backend (§5.7 y §5.9), README del frontend (§6.7), `docs/aviacore-arquitectura-harness.md`, `docs/aviacore-benchmark-guardrails.md` (privacidad), README raíz.

---

### Task 1: Tablas y modelos

**Files:** las 2 migraciones y 2 modelos. Test: `tests/Feature/ConversacionAsistenteTest.php` (esquema).

**Interfaces:**
- Produces:
  - `mnt_conversaciones_asistente`: `id`; `id_usuario` (FK a `mnt_usuarios`, `restrictOnDelete()`, **sin** unicidad); `uuid` (único); `nombre` (string 80, nullable); `nombre_origen` (string 12: `pendiente`|`automatico`|`manual`, por defecto `pendiente`); `estado` (string 12: `activa`|`eliminada`, por defecto `activa`); `eliminada_at` (nullable); `ultima_actividad_at`; `timestamps`. Índice `(id_usuario, estado, ultima_actividad_at)`.
  - `mnt_mensajes_asistente`: `id`; `id_conversacion_asistente` (FK, `restrictOnDelete()`); `rol` (string 10: `user`|`model`); `contenido` (text); `herramientas` y `sugerencias_modulos` (json, nullable); `es_error` (boolean, por defecto `false`); `en_contexto` (boolean, por defecto `true`); `created_at`. Índice `(id_conversacion_asistente, id)`.
  - `ConversacionAsistente` (constantes `ESTADO_ACTIVA`, `ESTADO_ELIMINADA`, `NOMBRE_PENDIENTE`, `NOMBRE_AUTOMATICO`, `NOMBRE_MANUAL`; `mensajes()` ordenados por `id`; `usuario()`) y `MensajeAsistente` (`UPDATED_AT = null`; `ROL_USUARIO = 'user'`, `ROL_MODELO = 'model'`; casts de json y boolean).

- [ ] **Step 1: Tests:** `test_un_usuario_puede_tener_varias_conversaciones` (dos filas con el mismo `id_usuario`), `test_el_uuid_es_unico`, `test_los_mensajes_se_leen_en_orden_con_sus_banderas_y_json`, `test_no_se_puede_borrar_una_conversacion_con_mensajes` (la FK restringe), `test_la_auditoria_no_gana_columnas_ni_referencias` (`Schema::getColumnListing('mnt_funcion_log_agent')` no contiene `id_usuario` ni nada con `conversacion_asistente`).
- [ ] **Step 2:** `$TEST --filter=ConversacionAsistenteTest` → FALLA.
- [ ] **Step 3:** Migraciones y modelos.
- [ ] **Step 4:** `$TEST --filter=ConversacionAsistenteTest` → PASA.
- [ ] **Step 5: Migrar la base de desarrollo (aditivo):** `migrate:status` (solo las 2 nuevas pendientes) → `migrate --pretend` (solo `create table`, índices y FK de las tablas nuevas) → `migrate` → conteos de las tablas de negocio y del log iguales a la línea base.

### Task 2: Configuración y servicio de conversación

**Files:** Create `config/asistente.php`, `Conversacion/ConversacionAsistenteService.php`. Test: ampliar `ConversacionAsistenteTest.php`.

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `config('asistente.conversacion')`: `contexto_mensajes` (6), `listado_max` (50), `mensajes_max_carga` (100), `nombre_max` (60), cada uno con su variable de entorno. El servicio lee cada valor con `max(1, (int) …)` y cae al defecto si no es numérico.
  - `ConversacionAsistenteService`:
    - `buscar(int $idUsuario, ?string $uuid): ?ConversacionAsistente` — la `activa` del usuario con ese UUID; `null` si el formato es inválido, es ajena, no existe o está eliminada.
    - `obtenerOCrear(int $idUsuario, ?string $uuid): ConversacionAsistente` — `buscar`; si no hay, crea una nueva con UUID generado por el servidor.
    - `contexto(ConversacionAsistente $c): array` — formato Gemini `[['role' => 'user'|'model', 'parts' => [['text' => …]]]]`: los últimos `contexto_mensajes` con `en_contexto = true` ordenados por `id`; si el primero es del modelo se descarta hasta que empiece con `user`; vacío si no queda ninguno.
    - `registrarTurno(ConversacionAsistente $c, string $mensajeUsuario, string $textoModelo, string $resultado, array $herramientas = [], array $sugerencias = []): void` — en `DB::transaction` inserta el mensaje del usuario y el del modelo (en ese orden) con `es_error = ($resultado === 'error')` y `en_contexto = ($resultado === 'exitoso')` en **ambos**, y actualiza `ultima_actividad_at`.
    - `listar(int $idUsuario): array` — hasta `listado_max` conversaciones `activas` por `ultima_actividad_at` descendente, cada una `['uuid', 'nombre', 'vista_previa', 'ultima_actividad', 'total_mensajes']`; `nombre` usa `nombreVisible`; `vista_previa` es el primer mensaje del usuario (90 caracteres); `ultima_actividad` formateada `d-m-Y h:i A` en `config('avicola.timezone')`.
    - `mensajes(ConversacionAsistente $c): array` — los últimos `mensajes_max_carga` en orden ascendente: `id`, `rol`, `contenido`, `herramientas`, `sugerencias_modulos`, `es_error`, `created_at` (ISO).
    - `renombrar(ConversacionAsistente $c, string $nombre): ConversacionAsistente` — `nombre_origen = manual`.
    - `eliminar(ConversacionAsistente $c): void` — solo `estado = eliminada` y `eliminada_at = now()`.
    - `nombreVisible(ConversacionAsistente $c): string` — `nombre` si existe; si no, **"Conversación"** (decisión del usuario: las conversaciones sin ningún turno exitoso, o cuyo nombre automático aún no llega, se listan así; no hay nombre provisional tomado de la consulta). El comienzo de la consulta solo se usa como respaldo al **guardar** un nombre automático cuando Gemini falla (T5), vía el método auxiliar `respaldoDeNombre(ConversacionAsistente $c): string` (primera consulta del usuario, hasta 50 caracteres, cortado en palabra; "Conversación" si no hay ninguna).
    - `uuidAuditoria(ConversacionAsistente $c): string` — seudónimo con formato UUID derivado de `hash_hmac('sha256', $c->uuid, config('app.key'))`; determinista, distinto del `uuid` y entre conversaciones.

- [ ] **Step 1: Tests** (con `Carbon::setTestNow`): `test_buscar_solo_devuelve_conversaciones_activas_del_usuario` (ajena, eliminada, inexistente y formato inválido dan `null`), `test_obtener_o_crear_con_uuid_ajeno_crea_una_nueva_y_no_toca_la_ajena`, `test_el_contexto_toma_los_ultimos_n_y_empieza_con_el_usuario` (con 10 mensajes y N = 6; con N impar descarta el mensaje inicial del modelo), `test_el_contexto_no_tiene_ventana_de_tiempo` (mensajes de hace 30 días entran), `test_saludo_bloqueo_y_error_no_entran_al_contexto`, `test_sin_mensajes_validos_el_contexto_es_vacio`, `test_registrar_turno_guarda_ambos_mensajes_en_orden_con_banderas_y_json`, `test_eliminar_solo_cambia_el_estado_y_conserva_los_mensajes`, `test_listar_ordena_por_actividad_trunca_la_vista_previa_y_excluye_eliminadas`, `test_listar_respeta_el_maximo_configurado`, `test_nombre_visible_es_conversacion_cuando_no_hay_nombre_aunque_haya_mensajes`, `test_respaldo_de_nombre_usa_el_comienzo_de_la_consulta_corta_en_palabra_y_tolera_conversacion_sin_mensajes`, `test_listar_muestra_conversacion_como_nombre_de_las_que_no_tienen_nombre_y_conserva_su_vista_previa`, `test_renombrar_marca_el_origen_manual`, `test_configuracion_invalida_cae_en_los_valores_por_defecto` (`0`, `-3`, `abc`), `test_el_uuid_de_auditoria_es_determinista_con_formato_uuid_y_distinto_por_conversacion`.
- [ ] **Step 2:** `$TEST --filter=ConversacionAsistenteTest` → FALLAN los nuevos.
- [ ] **Step 3:** Implementar configuración y servicio con las firmas de arriba.
- [ ] **Step 4:** `$TEST --filter=ConversacionAsistenteTest` → PASA.

### Task 3: El chat usa la conversación del servidor

**Files:** Modify `ChatRequest.php`, `AsistenteController.php`, `AsistenteOrquestadorService.php`. Test: ajustar `BenchmarkOrquestadorTest.php`; crear `tests/Feature/ConversacionAsistenteApiTest.php`.

**Interfaces:**
- Consumes: Task 2.
- Produces:
  - `ChatRequest::rules()`: `mensaje` igual; `uuid_conversacion` → `['nullable', 'string', 'max:64']`; se **quitan** las reglas de `historial` (si el cliente lo manda, se ignora y no da 422).
  - `AsistenteOrquestadorService::procesarConversacion` agrega `$resultado['resultado'] = $rastro->resultado` al arreglo que retorna (una línea en el método público, tras `ejecutarTurno`); nada más cambia.
  - `POST /asistente/chat`: `obtenerOCrear($user->id, $request->input('uuid_conversacion'))` → `contexto` → `procesarConversacion($mensaje, $contexto, $contextoFijo, uuidAuditoria($c))` → `registrarTurno(..., resultado)` → respuesta `{success, data: {respuesta, herramientas_utilizadas, sugerencias_modulos, uuid_conversacion, requiere_titulo}}` **sin** la clave `resultado`. `requiere_titulo = ($resultado === 'exitoso' && $c->nombre_origen === 'pendiente')`. Si el orquestador lanza: `registrarTurno` con `resultado = error` y el texto del 503 actual, y se conserva el 503 y el `Log::error`. Si la capa de conversación falla (excepción): `Log::error`, el chat continúa sin persistir y `uuid_conversacion` va en `null`.

- [ ] **Step 1: Tests** (`ConversacionAsistenteApiTest`, con `Http::fake`): `test_el_primer_mensaje_crea_la_conversacion_y_devuelve_su_uuid`, `test_el_segundo_mensaje_con_ese_uuid_envia_el_contexto_al_modelo` (`Http::assertSent` con el texto del turno anterior en `contents`), `test_un_uuid_ajeno_en_el_chat_crea_otra_conversacion_y_no_escribe_en_la_ajena`, `test_el_historial_del_cliente_se_ignora` (25 elementos con un turno falso del modelo: no hay 422 y el texto falso no llega a Gemini), `test_saludo_y_bloqueo_se_guardan_pero_no_entran_al_contexto_siguiente`, `test_un_error_del_proveedor_guarda_el_turno_como_error_y_responde_503`, `test_requiere_titulo_solo_tras_un_turno_exitoso_con_nombre_pendiente` (saludo → `false`; exitoso → `true`; con nombre ya asignado → `false`), `test_la_respuesta_no_expone_la_clave_resultado`, `test_la_auditoria_usa_un_seudonimo_estable_distinto_del_uuid_de_la_conversacion` (el log guarda `uuidAuditoria`, no el `uuid`, y no hay columna de usuario), `test_un_fallo_de_la_capa_de_conversacion_no_rompe_el_chat` (bind de un servicio que lanza: 200, respuesta normal y `uuid_conversacion` nulo), `test_el_contexto_respeta_el_limite_configurado`. En `BenchmarkOrquestadorTest` reemplazar `test_uuid_de_conversacion_se_guarda_y_se_valida` (hoy espera 422 por uuid inválido y que el log guarde el uuid del cliente) por una prueba que compruebe que el `uuid_conversacion` del log es el seudónimo derivado por el servidor. Buscar y ajustar cualquier otra prueba que compare el arreglo completo que retorna el orquestador (las revisadas leen claves sueltas).
- [ ] **Step 2:** `$TEST --filter="ConversacionAsistenteApiTest|BenchmarkOrquestadorTest|AsistenteTest"` → FALLAN los nuevos.
- [ ] **Step 3:** Implementar los tres cambios.
- [ ] **Step 4:** mismo comando → PASA; luego la suite completa aislada en verde.

### Task 4: Endpoints de conversaciones

**Files:** Create `AsistenteConversacionController.php`, `RenombrarConversacionRequest.php`. Modify `routes/api.php`. Test: ampliar `ConversacionAsistenteApiTest.php`.

**Interfaces:**
- Consumes: Task 2.
- Produces (bajo `auth:sanctum`, sin parámetros de usuario):
  - `GET /asistente/conversaciones` → `data: listar()`.
  - `GET /asistente/conversaciones/{uuid}` → `data: {uuid, nombre, mensajes}`.
  - `PATCH /asistente/conversaciones/{uuid}` con `nombre` (`required`, `string`, `min:1`, `max:60`, sin saltos de línea; se aplica `trim`) → `data: {uuid, nombre}`; los errores de validación son 422 con el formato estándar.
  - `DELETE /asistente/conversaciones/{uuid}` → `{success:true, message:'Conversación eliminada del historial.'}`.
  - Un UUID inválido, ajeno, inexistente o ya eliminado responde **404 `{success:false, message:'Conversación no encontrada.'}`** en los cuatro casos y en todos los verbos. (El spec decía que `DELETE` era idempotente y también que una eliminada da 404; se resuelve a favor del 404 uniforme para no permitir enumerar UUID, y el frontend trata el 404 del `DELETE` como éxito.)

- [ ] **Step 1: Tests:** `test_los_endpoints_requieren_autenticacion` (401 en los cuatro), `test_listar_devuelve_solo_las_activas_del_usuario_con_su_forma`, `test_abrir_devuelve_los_mensajes_en_orden_con_sus_banderas`, `test_abrir_limita_a_los_ultimos_100_mensajes`, `test_un_usuario_no_abre_renombra_ni_elimina_la_conversacion_de_otro` (404 y datos intactos), `test_el_404_es_identico_para_ajena_inexistente_invalida_y_eliminada`, `test_eliminar_cambia_el_estado_sin_borrar_filas` (las conversaciones y los mensajes siguen en la base; `eliminada_at` seteado), `test_una_eliminada_no_se_lista_no_se_abre_y_el_chat_con_su_uuid_crea_una_nueva`, `test_renombrar_valida_longitud_y_saltos_de_linea_y_marca_origen_manual`, `test_las_respuestas_no_incluyen_ids_de_usuario`, `test_el_benchmark_no_incluye_datos_de_conversaciones`.
- [ ] **Step 2:** `$TEST --filter=ConversacionAsistenteApiTest` → FALLAN los nuevos.
- [ ] **Step 3:** Implementar controlador, request y rutas.
- [ ] **Step 4:** `$TEST --filter=ConversacionAsistenteApiTest` → PASA.

### Task 5: Nombre automático

**Files:** Create `Conversacion/TituloConversacionService.php`. Modify `AsistenteConversacionController.php` y `routes/api.php`. Test: `tests/Feature/TituloConversacionTest.php`.

**Interfaces:**
- Consumes: Tasks 2 y 4; `GeminiApiClient::generarContenido($contents, [], $systemInstruction)`; `SystemLeakageGuard::sanear()`.
- Produces:
  - `TituloConversacionService::generar(ConversacionAsistente $c): ConversacionAsistente`:
    1. Si `nombre_origen !== 'pendiente'`, devuelve la conversación sin llamar al modelo.
    2. Toma el primer mensaje del usuario con `en_contexto = true` y la respuesta del modelo que le sigue; si no hay ninguno (solo saludos, bloqueos o errores), **no llama al modelo ni cambia nada** y devuelve la conversación.
    3. Llama **una sola vez** a Gemini sin herramientas y con una instrucción mínima (título de 3 a 6 palabras en español, sin comillas ni punto final), enviando la consulta y la respuesta truncadas a 300 caracteres.
    4. Normaliza la respuesta: una línea, sin comillas ni saltos, máximo `nombre_max` caracteres, pasada por `sanear()`. Si queda vacía, supera el máximo o `sanear()` la cambia, usa el respaldo (`respaldoDeNombre`, el comienzo de la consulta; nunca el literal "Conversación" si hay consulta).
    5. Cualquier excepción del modelo se captura y usa el respaldo (no se relanza).
    6. Guarda `nombre` y `nombre_origen = automatico` **siempre que hubo intento**, para que un 503 no genere reintentos.
  - `POST /asistente/conversaciones/{uuid}/titulo` → `data: {nombre}`; mismo 404 uniforme; `->middleware('throttle:10,1')`.
  - La llamada de nombrado **no se audita** en el Benchmark (se documenta que consume tokens que sus estadísticas no cuentan).

- [ ] **Step 1: Tests** (`Http::fake` y `Http::assertSentCount`): `test_genera_el_nombre_con_gemini_y_lo_guarda_como_automatico`, `test_solo_envia_la_primera_consulta_y_respuesta_truncadas_y_sin_herramientas`, `test_si_gemini_falla_usa_el_respaldo_y_no_reintenta` (503 → nombre = comienzo de la consulta, `nombre_origen = automatico`, y una segunda llamada al endpoint no vuelve a llamar a Gemini), `test_respuesta_vacia_larga_o_con_nombre_de_tabla_cae_en_el_respaldo` (`mnt_gastos`, 200 caracteres, cadena vacía), `test_el_nombre_queda_en_una_linea_sin_comillas`, `test_un_nombre_manual_nunca_se_pisa` (cero llamadas a Gemini), `test_sin_turnos_exitosos_no_llama_al_modelo_ni_cambia_el_estado` (conversación con solo un saludo), `test_el_endpoint_da_404_para_conversaciones_ajenas`, `test_el_endpoint_tiene_limite_de_peticiones` (la 11.ª en un minuto da 429).
- [ ] **Step 2:** `$TEST --filter=TituloConversacionTest` → FALLA.
- [ ] **Step 3:** Implementar el servicio, la acción del controlador y la ruta.
- [ ] **Step 4:** `$TEST --filter=TituloConversacionTest` → PASA; luego la suite completa aislada en verde.

### Task 6: Cliente HTTP y store del frontend

**Files:** Modify `services/asistente.js`, `stores/asistente.js`, `stores/auth.js`.

**Interfaces:**
- Consumes: Tasks 3, 4 y 5.
- Produces:
  - `services/asistente.js`: `enviarMensaje({ mensaje, uuid_conversacion })` (sin `historial`; devuelve `data` con `uuid_conversacion` y `requiere_titulo`), `listarConversaciones()`, `obtenerConversacion(uuid)`, `renombrarConversacion(uuid, nombre)`, `eliminarConversacion(uuid)`, `solicitarTitulo(uuid)`.
  - `stores/asistente.js`: la clave de `localStorage` es `avicola_asistente_uuid` (lectura y escritura dentro de `try/catch`; el chat funciona sin ella). Estado nuevo: `uuidConversacion` (inicial desde `localStorage`), `cargandoConversacion`, `conversaciones`, `cargandoHistorial`. Se eliminan `nuevoUuidConversacion` y el UUID generado en el navegador. Acciones:
    - `cargarConversacion()` — una vez al montar el widget si hay UUID: `obtenerConversacion`, mapea cada mensaje a `{ id: 'srv-<id>', role: rol, text: contenido, timestamp: hh:mm local, herramientas, sugerenciasModulos, esError }`; si responde 404 borra la clave y deja el chat vacío; cualquier otro fallo deja el chat vacío y usable sin notificar.
    - `limpiarHistorial()` — **se conserva el nombre y la acción que ya llama el botón "Limpiar conversación" (D1)**, pero su contenido cambia: si no hay mensajes no hace nada; si hay, borra la clave de `localStorage`, pone `uuidConversacion = null`, vacía `mensajes`, invalida cualquier petición en curso y notifica con `notify.info('Conversación guardada en el historial.')`. **No llama al servidor y no crea ninguna conversación:** el UUID nuevo lo genera el servidor con el siguiente mensaje, y `enviarMensaje` lo guarda al recibirlo. (Ya no existe `nuevoUuidConversacion` en el navegador.)
    - `abrirConversacion(uuid)` — carga esa conversación en `mensajes` y guarda su UUID; en error 404 la saca del listado; el chat actual **no** se vacía hasta que la carga termine bien.
    - `cargarHistorial()`, `renombrarConversacion(uuid, nombre)` y `eliminarConversacion(uuid)` (si era la abierta, vacía el chat y borra la clave; el 404 se trata como éxito).
    - `enviarMensaje(texto)` — igual que hoy pero sin `historial`; tras la respuesta guarda `data.uuid_conversacion` (si es `null`, no toca `localStorage`); si `data.requiere_titulo`, llama a `solicitarTitulo(uuid)` **sin esperar y sin notificar errores**; una respuesta tardía de una conversación que ya no es la actual **no** se agrega al chat ni cambia el UUID.
    - `reiniciar()` — vacía mensajes, listado y banderas, borra la clave e invalida peticiones en curso.
  - Las peticiones usan contadores como `stores/benchmark.js`: solo la última de cada recurso (carga, apertura, listado) escribe en el estado.
  - `stores/auth.js`: en el `finally` de `logout()` llama a `useAsistenteStore().reiniciar()`.

- [ ] **Step 1:** Implementar servicio y store.
- [ ] **Step 2:** `npm run build` en `avicola-frontend/` → sin errores. (El frontend no tiene corredor de pruebas: se verifica con el build y en el navegador, y el README lo registra como deuda.)
- [ ] **Step 3: Comportamientos que la Task 7/8 debe comprobar en el navegador por esta tarea** (no hay pruebas automáticas del store): tras `limpiarHistorial()` el chat queda vacío y `localStorage` no tiene `avicola_asistente_uuid`; el servidor **no recibió ninguna petición** (pestaña de red) y no se creó ninguna conversación (conteo de solo lectura de `mnt_conversaciones_asistente` igual); pulsarlo tres veces seguidas con el chat vacío no cambia nada y no notifica; el siguiente mensaje crea una conversación con UUID **distinto** al anterior y lo guarda; la conversación anterior sigue abriéndose desde el historial con todos sus mensajes; con un mensaje en curso el botón está deshabilitado (y si se hace Limpiar justo antes de que llegue la respuesta, esta no aparece en el chat vacío ni repone la clave).

### Task 7: Widget — botón Historial, Limpiar y carga

**Files:** Modify `components/asistente/AsistenteChatWidget.vue`.

**Interfaces:**
- Consumes: Tasks 6 y 8 (el modal; se puede cablear con un componente vacío y completar en la Task 8).
- Produces: en la cabecera, de izquierda a derecha: **Historial** (`History`, `title="Historial de conversaciones"`, `aria-label` igual) → **Limpiar** (`Trash2`; **se conservan tal cual** el botón, el ícono y los `title`/`aria-label` "Limpiar conversación", y su `@click` sigue llamando a `limpiarHistorial()`, ahora con el comportamiento de D1) → minimizar. Al montarse llama a `cargarConversacion()`; mientras `cargandoConversacion` muestra `LoadingState` ("Cargando conversación...") en el área de mensajes y deshabilita el campo y el botón de enviar; después hace scroll al último mensaje. Mientras `cargando` (hay un mensaje en curso) los botones Historial y Limpiar quedan deshabilitados. El botón de Limpiar no pide confirmación (D1).

- [ ] **Step 1:** Implementar el widget.
- [ ] **Step 2:** `npm run build` → sin errores.

### Task 8: Modal de historial, confirmar eliminación y renombrar

**Files:** Create `components/asistente/HistorialConversacionesModal.vue`, `RenombrarConversacionModal.vue`. Modify `AsistenteChatWidget.vue` (montarlos).

**Interfaces:**
- Consumes: Task 6; `BaseModal`, `BaseConfirmModal`, `FormField`, `RowActionButton`, `EmptyState`, `LoadingState`, `useFormErrors`, `useNotificationStore`.
- Produces:
  - `HistorialConversacionesModal` — props `isOpen`; emite `close`. Sobre `BaseModal` (`max-width="md"`): encabezado en el slot `#header` con el título **"Historial de conversaciones"** y el subtítulo **"Consulta y retoma tus consultas anteriores"**; al abrirse pide `cargarHistorial()`; mientras carga `LoadingState`; sin conversaciones `EmptyState` (icono `History`, "Aún no tienes conversaciones"); si falla, `notify.error`. Cada fila (sin tarjetas anidadas, como la lista de `AveMovimientosModal`: `border-borde`, fondo `crema/40`, `rounded-2xl`) tiene un **botón principal** con ícono `MessagesSquare`, el nombre (negrita, una línea con `truncate`), la fecha `DD-MM-YYYY hh:mm AM/PM` y la vista previa (una línea con puntos suspensivos), y a su lado **dos `RowActionButton`** hermanos (no anidados dentro del botón): lápiz (`tone="edit"`, "Cambiar nombre") y papelera (`tone="danger"`, "Quitar del historial"). La conversación abierta se resalta con borde `salvia`. Clic en el botón principal: `abrirConversacion(uuid)`, y si sale bien, cierra el modal y el chat baja al último mensaje. Papelera: `BaseConfirmModal` ("¿Quitar esta conversación de tu historial?", descripción "Dejará de aparecer en tu historial. Podrás empezar otra conversación cuando quieras.", `is-destructive`, `:loading`); al confirmar, `eliminarConversacion` y `notify.success('Conversación quitada del historial.')`. Pie centrado con **"Cerrar"** (`.btn-secundario`). Todo texto con interpolación `{{ }}`, nunca `v-html`.
  - `RenombrarConversacionModal` — props `isOpen`, `conversacion`; emite `close` y `renamed`. `BaseModal` pequeño, título "Cambiar nombre", un `FormField` "Nombre de la conversación" (`maxlength` 60, `required`), validación local de no vacío, mapeo de los 422 al campo con `useFormErrors`, botones centrados `Cancelar` y `Guardar` (con `Loader2` mientras envía).

- [ ] **Step 1:** Implementar los dos modales y montarlos en el widget.
- [ ] **Step 2:** `npm run build` → sin errores.
- [ ] **Step 3: Verificación en el navegador** (servidor de desarrollo en el puerto 5173, el único que admite el CORS del backend; **pedir autorización antes**, porque crea conversaciones reales). **Caso de las conversaciones débiles (decisión del usuario):** enviar solo "hola" (o un mensaje bloqueado, p. ej. "suma 2+3"), pulsar Limpiar y abrir el historial → la conversación aparece con el nombre **"Conversación"**, su fecha y la vista previa ("hola"), y **no** recibe nombre automático aunque se espere; dos conversaciones débiles se distinguen por fecha y vista previa. Luego: enviar 2 preguntas y recargar → la conversación reaparece con sus insignias; "Limpiar conversación" (el botón de siempre) vacía el chat y la anterior aparece en el historial con nombre automático (esperar a que llegue; mientras tanto se lista como "Conversación", con su fecha y la vista previa del comienzo de lo que escribiste); abrir una conversación antigua y continuarla; renombrar; quitar con confirmación y comprobar que sigue en la base (consulta de solo lectura); quitar la abierta vacía el chat; modales apilados (historial + confirmar o renombrar) se ven y cierran en orden; con un UUID basura en `localStorage` el chat arranca vacío; cerrar sesión e iniciar con otro usuario (si existe; si no, comprobar que el widget empieza vacío al volver a entrar sin recargar) no muestra mensajes ajenos; el saludo "hola" reaparece tras recargar y no cuenta en `turnos_historial` del turno siguiente en Benchmark; un nombre con `<b>x</b>` escrito en renombrar se ve como texto; vista móvil sin desbordes.

### Task 9: Documentación y verificación final

- [ ] **Step 1:** Suite completa aislada en verde, `npm run build` limpio y conteos de las tablas de negocio y del log iguales a la línea base.
- [ ] **Step 2:** Pint solo sobre los archivos PHP creados o modificados.
- [ ] **Step 3:** Documentar: README del backend (§5.7: `chat` sin `historial`, con `uuid_conversacion`/`requiere_titulo`, endpoints de conversaciones, `config/asistente.php`; §5.9: el seudónimo de auditoría y que la auditoría sigue sin columna de usuario), README del frontend (§6.7: persistencia por `localStorage`, historial, "Limpiar conversación" (suelta el UUID; el nuevo nace con el siguiente mensaje), nombre automático), `docs/aviacore-arquitectura-harness.md` (estructura de carpetas, flujo y tablas), `docs/aviacore-benchmark-guardrails.md` (privacidad: de "anónimo" a "sin referencia al usuario, con seudónimo derivado", y que el nombrado consume tokens que el Benchmark no cuenta), README raíz (etapa). Dejar constancia de D5.
- [ ] **Step 4:** Resumen al usuario con lo creado, los valores por defecto y lo pendiente (depuración de conversaciones antiguas, reducir la base fija de tokens). Sin commits hasta que lo ordene.

## Fuera de alcance

- Depuración de conversaciones ocultas o muy antiguas (el historial crece sin límite hasta que exista).
- Reducir la base fija de ≈ 2.060 tokens por llamada (la palanca de ahorro más grande).
- Búsqueda dentro de conversaciones, exportar o compartir; sincronización en vivo entre pestañas.
- Auditar la llamada de nombrado en el Benchmark.

## Self-Review

- **Cobertura del spec:** persistir y recuperar por UUID de `localStorage` (T2, T3, T6, T7); nada se borra, solo estados (T1, T2, T4); Historial junto al ícono de limpiar con el ícono de traslados (T7, T8); abrir, eliminar y renombrar (T4, T6, T8); nombre automático con respaldo (T5); contexto de 6 mensajes armado en el servidor (T2, T3); modal con la estructura de la imagen y la paleta del sistema (T8); privacidad y seudónimo de auditoría (T3, T9); cierre de sesión (T6).
- **Consistencia de nombres:** `buscar`, `obtenerOCrear`, `contexto`, `registrarTurno`, `listar`, `mensajes`, `renombrar`, `eliminar`, `nombreVisible` y `uuidAuditoria` (T2) se usan en T3, T4 y T5; `TituloConversacionService::generar` (T5) lo invoca el controlador de T4; las acciones del store (T6) las usan T7 y T8.
- **Lo que cambia de lo ya construido:** `ChatRequest` (pierde `historial` y cambia la regla de `uuid_conversacion`), una prueba de `BenchmarkOrquestadorTest`, el retorno del orquestador (una clave más), `authStore.logout` y la cabecera del widget. El esquema de la auditoría y el Benchmark no cambian.
- **Proporción:** 9 tareas; dos tablas, dos servicios, un controlador y cambios acotados en el frontend. Renombrar (backend en T4, frontend en T8) es separable.
