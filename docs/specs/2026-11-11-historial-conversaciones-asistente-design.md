# Diseño: Conversaciones persistentes e historial del asistente AviaCore

Fecha: 2026-11-11 · Repos: `avicola-backend-api` (Laravel, PostgreSQL) y `avicola-frontend` (Vue 3 + Pinia + Tailwind)

## 1. Intención y por qué

Hoy la conversación con AviaCore vive solo en la memoria del navegador (`stores/asistente.js`): recargar la página la pierde. Además, el historial que se envía al modelo lo arma el navegador y el servidor lo acepta sin verificar (`ChatRequest` solo exige `role` y `parts`), y cada mensaje viejo se reenvía y consume tokens.

El usuario pidió:

1. Que la conversación **sobreviva a recargar la página**, recuperándose con un UUID guardado en `localStorage`.
2. Que **nunca se borren** las conversaciones: cualquier "limpiar" o "eliminar" solo cambia su estado para que dejen de aparecerle al usuario.
3. Un botón **Historial**, junto al ícono de limpiar en la cabecera del chat, que abre un modal con las conversaciones anteriores; al elegir una, se carga en el chat principal. Desde ese modal también se puede "eliminar" una (cambio de estado).
4. Que cada conversación reciba un **nombre automático** tomado de su contexto (la pregunta y la respuesta del agente).
5. Que al modelo solo se le envíe una **cantidad acotada de mensajes recientes** para ahorrar tokens.
6. Que el modal siga la estructura de la imagen de referencia y respete el diseño del frontend (componentes, paleta y modales existentes, ícono de historial de Inventario de Aves).

## 2. Objetivos, no-objetivos y anti-objetivos

**Objetivos**
- Conversaciones guardadas en el servidor, **varias por usuario**, cada una con su UUID, nombre, estado y mensajes con una bandera de rol (`user` / `model`).
- Recuperar la conversación del chat por el UUID de `localStorage`; sin UUID válido, el chat empieza vacío.
- Modal de historial: listar, abrir, renombrar y eliminar (lógicamente).
- Nombre automático generado a partir de la primera consulta resuelta y su respuesta, con un respaldo que nunca falla.
- Contexto al modelo limitado a los últimos N mensajes útiles, armado en el servidor.

**No-objetivos (esta fase)**
- Borrado físico de conversaciones o mensajes, y depuración automática de conversaciones ocultas.
- Buscar dentro de las conversaciones, exportarlas o compartirlas.
- Sincronizar en vivo el chat entre pestañas o dispositivos (otra pestaña ve los cambios al recargar).
- Reducir la base fija de ≈ 2.060 tokens por llamada (prompt del sistema + herramientas): es la mayor palanca de ahorro, pero otro proyecto.

**Anti-objetivos (fracaso aunque "funcione")**
- Que un usuario pueda ver, abrir, renombrar, eliminar o alimentar el contexto con la conversación de otro (incluido el caso de dos usuarios en el mismo navegador).
- Que el navegador pueda inyectar turnos falsos del modelo en el contexto.
- Que un fallo al nombrar o al guardar rompa o retrase la respuesta del chat.
- Que el nombre automático exponga nombres de tablas, rutas o texto del sistema, o se renderice como HTML.
- Que la auditoría del Benchmark pueda unirse con las conversaciones por un identificador, rompiendo que **no referencia al usuario** (ver decisión D5).
- Que la interfaz diga "borrado" cuando el dato se conserva.

## 3. Restricciones (reglas de cada repo)

**Backend** (`.agents/skills/avicola-backend`): prefijo `mnt_`; clave `id`; foráneas `id_<entidad>`; `restrictOnDelete()`; controladores delgados + `FormRequest` + servicio; `DB::transaction`; JSON `{success, message, data}`; rutas bajo `auth:sanctum`; mensajes en español.
**Pruebas backend:** siempre aisladas con `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test` (un `php artisan test` a secas vacía la base de desarrollo).
**Base de datos de desarrollo:** solo migraciones aditivas con `php artisan migrate`; nunca `migrate:fresh`/`refresh`.
**Frontend** (`.agents/skills/avicola-frontend` + `DESIGN.md`): la vista y el widget orquestan; HTTP en `services/`; estado en el store; formularios con `FormField`; botones de modal **centrados**; `BaseConfirmModal` para eliminar; `notify.*` (nunca `alert()` ni `confirm()`); tokens bosque/salvia/crema/verdoso (y `destructivo` para peligro); íconos Lucide; sin emojis en la UI; reutilizar `common/`; sin tarjetas anidadas con sombras; no se modifican componentes globales.
**Global:** no commitear sin orden del usuario y sin línea de coautor; el `push` lo hace el usuario; sin `DELETE`/`INSERT` manuales en la base de desarrollo sin autorización.

## 4. Decisiones del usuario (tal cual las dio)

- Nada se borra: solo cambia el estado.
- El UUID de la sesión se guarda en `localStorage` y con él se busca la conversación más actual.
- El botón **Historial** va en la cabecera del chat, a un lado del ícono de limpiar, con el ícono `History` de Inventario de Aves (traslados).
- Al hacer clic en una conversación del historial se carga en el chat principal.
- Se puede "eliminar" una conversación del historial, pero solo cambiando su estado.
- El nombre lo toma del contexto (consulta y respuesta del agente).
- El modal sigue la estructura de la imagen: título "Historial de conversaciones", subtítulo "Consulta y retoma tus consultas anteriores", una fila por conversación (ícono, nombre, fecha, vista previa truncada, lápiz y papelera) y botón "Cerrar" al pie.

## 5. Decisiones del diseñador (el usuario puede cambiarlas)

**D1. "Limpiar conversación" se mantiene tal cual en la cabecera (mismo botón, ícono y nombre); lo que cambia es lo que hace por dentro.** *(Aclarado por el usuario el 11-11-2026.)* El botón existente **vacía el chat y suelta el UUID del navegador**; la conversación que se estaba viendo sigue `activa` en el servidor y aparece en el historial. **No se crea ninguna conversación al pulsarlo:** el UUID nuevo nace **cuando el usuario envía el siguiente mensaje**, porque lo genera el servidor (D3). Consecuencias deliberadas: pulsar Limpiar varias veces seguidas no crea filas vacías (una conversación existe solo si hubo al menos un mensaje); no hay llamada al servidor ni confirmación (no destruye nada y es reversible); se avisa con `notify.info('Conversación guardada en el historial.')`; si el chat ya está vacío el botón no hace nada. **Limpiar queda deshabilitado mientras hay un mensaje en curso**, igual que el botón Historial: de lo contrario la respuesta tardía llegaría a un chat ya vacío y repondría el UUID anterior.

Una conversación puede quedar "débil" (por ejemplo solo un saludo, un bloqueo de guard o un error del proveedor, sin ningún turno exitoso): se guarda, **nunca recibe nombre automático** (D4) y **se muestra en el historial con el nombre "Conversación"** *(decidido por el usuario el 11-11-2026)*. Su fila sigue mostrando la fecha y la vista previa (el comienzo de lo que escribió), que es lo que permite distinguirla de otras. Con datos reales: 4 de 13 conversaciones de la auditoría (31 %) no tienen ningún turno exitoso y 5 tienen un solo turno. Se muestran todas en el historial (opción 1); ocultar las que no tengan un turno exitoso queda como ajuste posible tras observar el uso real (ver sección 11).

**Regla del nombre visible (una sola, en un solo lugar):** si la conversación tiene `nombre`, se muestra ese; si no lo tiene, se muestra **"Conversación"**. No existe un nombre "provisional" tomado del comienzo de la consulta: ese texto solo se usa como **respaldo al guardar un nombre automático cuando Gemini falla** (D4), caso en que la conversación sí tuvo un turno exitoso y el nombre queda guardado. Entre que una conversación recibe su primer turno exitoso y llega el nombre automático (una petición aparte), su nombre visible también es "Conversación".

**D2. Estados: `activa` y `eliminada`.** "Eliminar" desde el historial marca `estado = eliminada` y registra `eliminada_at`; los mensajes y la conversación permanecen en la base. Una conversación eliminada no se lista, no se abre y no recibe mensajes; para el usuario es indistinguible de una que no existe (404). La interfaz dice "Se quitará de tu historial" y no "se borrará".

**D3. El UUID de `localStorage` es de la conversación y lo genera el servidor.** Lo crea el servidor en el primer mensaje y lo devuelve; el navegador lo guarda en la clave `avicola_asistente_uuid`. No es un secreto ni una credencial: **cada acceso se valida contra el usuario autenticado** (anti-IDOR, igual que el contexto fijo). Un UUID ajeno, inexistente o de una conversación eliminada se trata como "no hay conversación": el chat sigue usable (el servidor crea una nueva si se envía un mensaje) y el navegador borra la clave. Sin respaldo "la más reciente": si el usuario pulsó Limpiar, no debe reaparecer sola una conversación vieja; para eso está el historial.

**D4. El nombre automático lo genera el modelo, en una llamada aparte y corta, tras la primera consulta resuelta.**
- Cuando un turno termina `exitoso` y la conversación aún no tiene nombre, la respuesta del chat incluye `requiere_titulo: true` y el navegador pide el nombre en un `POST` separado. Así el nombre **nunca suma latencia** a la respuesta del chat y no depende de colas (el contenedor solo ejecuta `php artisan serve`; no hay *worker*).
- Se envía a Gemini solo la primera consulta y su respuesta (truncadas a 300 caracteres cada una), con una instrucción mínima: título de 3 a 6 palabras en español, sin comillas ni punto final. Sin herramientas ni el prompt del sistema del chat (≈ 150 tokens en total).
- **Respaldo garantizado:** si Gemini falla (ya hubo errores 503), devuelve algo vacío, demasiado largo o sospechoso, el nombre es el comienzo de la primera consulta del usuario (hasta 50 caracteres, cortado en palabra). Siempre hay nombre; la llamada se intenta **una sola vez** por conversación (`nombre_origen` pasa de `pendiente` a `automatico`), de modo que un 503 no genere reintentos.
- **Saneado:** una línea, sin comillas ni saltos, máximo 60 caracteres, pasado por `SystemLeakageGuard::sanear()`; si el saneado cambia el texto, se usa el respaldo. Se muestra siempre con interpolación `{{ }}`, nunca como HTML.
- **Un saludo, un bloqueo o un error no disparan el nombre**; mientras tanto la lista la muestra como "Conversación" (ver D1).
- Un nombre `manual` (renombrado por el usuario) nunca se regenera.
- La llamada de nombrado **no se audita** en el Benchmark (se mide el chat, no los metadatos). Consume tokens reales que no aparecen en sus estadísticas; se documenta.

**D5. Privacidad: la auditoría sigue sin referenciar al usuario.** Las conversaciones sí están ligadas a un usuario y guardan el texto completo de cada mensaje, por lo que hoy ese texto existiría en dos tablas (la de conversaciones, con usuario, y la de auditoría, sin él). Para no volver a unirlas, el `uuid_conversacion` de `mnt_funcion_log_agent` pasa a ser un **seudónimo** `HMAC-SHA256(uuid de la conversación, APP_KEY)` con formato de UUID: determinista (todos los turnos de una conversación comparten seudónimo), pero imposible de enlazar con la tabla de conversaciones sin la clave de la aplicación. Es **seudonimización, no anonimato absoluto**: quien tenga la base y la clave podría reconstruirlo, y quien solo tenga la base podría correlacionar por texto y hora. La documentación deja de decir "anónimo" y dice "sin referencia al usuario". La auditoría no gana ninguna columna ni clave nueva y el Benchmark no lee las tablas de conversaciones.

**D6. Contexto al modelo: por cantidad, sin límite de edad.** Se envían los últimos **6** mensajes con `en_contexto = true`, siempre empezando por uno del usuario. Se descarta la ventana de tiempo que se había propuesto antes (60 min) porque contradice el historial: al retomar una conversación de hace días el modelo debe recordar sus últimos turnos. Entran al contexto solo los turnos `exitoso`; saludos, bloqueos de guards y errores se guardan y se muestran, pero no se reenvían. Un contexto vacío no es un error.

**D7. El historial no expira ni se recorta.** No hay expiración ni tope de mensajes guardados (borrar sería contradictorio). Para acotar el costo de cargar: el listado devuelve las 50 conversaciones más recientes y al abrir una se cargan sus últimos 100 mensajes. La depuración de conversaciones ocultas o muy antiguas queda para una fase posterior, coherente con la depuración manual del Benchmark.

**D8. Renombrar está incluido.** La imagen de referencia trae un lápiz y es natural corregir un nombre automático. Es la única parte separable: se implementa como la última tarea y se puede retirar sin afectar el resto.

**D9. El modal se adapta a la paleta clara del sistema.** La imagen es un tema oscuro con acentos azules; DESIGN.md prohíbe esos clichés y define fondo blanco, `bosque`, `salvia`, `crema` y `verdoso`. Se conserva la **estructura** (encabezado con subtítulo, filas con ícono, nombre, fecha, vista previa y acciones, botón Cerrar), no los colores. La conversación abierta actualmente se resalta con borde `salvia`, como la fila marcada de la imagen.

**D10. Confirmación solo para eliminar.** Limpiar no la pide (D1). Eliminar usa `BaseConfirmModal`: "¿Quitar esta conversación de tu historial?".

## 6. Comportamiento

**Chat (`POST /asistente/chat`).** Recibe `mensaje` y, opcional, `uuid_conversacion` (el de `localStorage`). Ya no usa el `historial` del cliente (si llega, se ignora). El servidor resuelve la conversación del usuario (D3), arma el contexto (D6), llama al orquestador pasándole el seudónimo de auditoría (D5), guarda el turno completo (usuario y modelo, en una transacción) y responde con la forma actual más `uuid_conversacion` (el real) y `requiere_titulo`. Si el orquestador falla, se guarda el turno marcado como error y se conserva el 503 actual.

**Cargar el chat al abrir la aplicación.** Si hay UUID en `localStorage`, el widget pide `GET /asistente/conversaciones/{uuid}`; mientras carga muestra `LoadingState` y deshabilita el envío. Si responde 404, borra la clave y muestra el chat vacío.

**Historial.** Botón con ícono `History` entre la papelera y el minimizar. El modal lista las conversaciones `activas` del usuario por última actividad: ícono `MessagesSquare`, nombre, fecha `DD-MM-YYYY hh:mm AM/PM` (zona de negocio) y vista previa (primera consulta, una línea con puntos suspensivos). Clic en una fila: se carga en el chat, se actualiza la clave de `localStorage`, el modal se cierra y el chat baja al último mensaje. Con la lista vacía: `EmptyState`. Si falla la carga o la apertura: `notify.error` y el chat actual queda intacto.

**Eliminar del historial.** Papelera → `BaseConfirmModal` → `DELETE` (cambio de estado). Si era la conversación abierta, el chat se vacía y se borra la clave.

**Renombrar.** Lápiz → modal pequeño con `FormField` "Nombre de la conversación" (1 a 60 caracteres) → `PATCH`; los errores 422 se muestran bajo el campo.

**Cerrar sesión.** `authStore.logout()` reinicia el estado del asistente y borra la clave, de modo que otro usuario en el mismo navegador no ve mensajes ajenos. Si la clave sobrevive (por ejemplo, token vencido), D3 lo cubre: el servidor responde 404 al otro usuario.

## 7. Datos

**`mnt_conversaciones_asistente`:** `id`; `id_usuario` (FK a `mnt_usuarios`, `restrictOnDelete()`; **sin** restricción de unicidad: varias por usuario); `uuid` (único); `nombre` (string 80, nullable); `nombre_origen` (`pendiente` | `automatico` | `manual`); `estado` (`activa` | `eliminada`, índice); `eliminada_at` (nullable); `ultima_actividad_at`; `timestamps`. Índice `(id_usuario, estado, ultima_actividad_at)`.

**`mnt_mensajes_asistente`:** `id`; `id_conversacion_asistente` (FK, `restrictOnDelete()`); `rol` (`user` | `model`); `contenido` (text); `herramientas` y `sugerencias_modulos` (json, nullable, para repintar las insignias y atajos al recargar); `es_error` (boolean); `en_contexto` (boolean); `created_at`. Índice `(id_conversacion_asistente, id)`.

Sin ninguna clave entre estas tablas y `mnt_funcion_log_agent`.

## 8. API (todas bajo `auth:sanctum`; todas operan solo sobre el usuario autenticado)

| Método | Ruta | Efecto |
|---|---|---|
| POST | `/asistente/chat` | Ver sección 6 |
| GET | `/asistente/conversaciones` | Listado: `uuid`, `nombre` (o "Conversación" si aún no tiene), `vista_previa`, `ultima_actividad`, `total_mensajes` (50 más recientes, solo `activas`) |
| GET | `/asistente/conversaciones/{uuid}` | Últimos 100 mensajes (`id`, `rol`, `contenido`, `herramientas`, `sugerencias_modulos`, `es_error`, `created_at`) |
| PATCH | `/asistente/conversaciones/{uuid}` | Renombrar (`nombre`, 1 a 60 caracteres) → `nombre_origen = manual` |
| DELETE | `/asistente/conversaciones/{uuid}` | `estado = eliminada` (idempotente) |
| POST | `/asistente/conversaciones/{uuid}/titulo` | Genera el nombre automático (D4); si ya hay nombre, lo devuelve sin llamar al modelo; límite de 10 por minuto |

Un UUID con formato inválido, ajeno, inexistente o eliminado responde 404 con el mismo mensaje en todos los casos.

## 9. Pruebas

- **Aislamiento:** un usuario no lista, abre, renombra, elimina ni genera el nombre de la conversación de otro (404); dos usuarios con sus propias conversaciones no se mezclan; un `uuid_conversacion` ajeno en el chat crea una conversación nueva en lugar de usar la ajena.
- **Contexto:** últimos N mensajes, empieza con el usuario, excluye saludos, bloqueos y errores, vacío sin mensajes válidos; el `historial` enviado por el cliente se ignora (aunque traiga 25 elementos o turnos falsos del modelo).
- **Estados:** eliminar solo cambia el estado (los mensajes siguen en la base), idempotente; una conversación eliminada no se lista ni se abre; el chat con su UUID crea una nueva.
- **Nombre:** se genera una sola vez tras el primer turno `exitoso`; fallo de Gemini, respuesta vacía o con `mnt_` caen en el respaldo (comienzo de la consulta); un nombre manual no se pisa; un saludo no dispara el nombre y la conversación se lista como "Conversación"; el nombre nunca se interpreta como HTML (verificación en el navegador).
- **Auditoría:** `mnt_funcion_log_agent` no cambia de esquema; su `uuid_conversacion` es estable entre turnos de una conversación, distinto del UUID de la conversación y distinto entre conversaciones.
- **Frontend (build y navegador, no hay corredor de pruebas):** recargar conserva la conversación; Limpiar vacía el chat y la conversación aparece en el historial; abrir una conversación antigua y continuar; eliminar la abierta vacía el chat; cerrar sesión e iniciar con otro usuario no muestra mensajes ajenos; el modal se ve bien en móvil y con muchas conversaciones; los modales apilados (historial + confirmar o renombrar) se ven y se cierran en orden.

## 10. Qué queda para quien construya (decide sin consultar)

Nombres exactos de clases y métodos, redacción del *prompt* de nombrado, valores de configuración (por defecto: 6 mensajes de contexto, 50 conversaciones listadas, 100 mensajes cargados, 60 caracteres de nombre, 10 peticiones por minuto para nombrar), el algoritmo de corte del respaldo del nombre y los textos menores de la interfaz.

## 11. Puntos abiertos para el usuario

1. **D1** (Limpiar mantiene su botón y solo cambia por dentro; la conversación anterior queda en el historial y el UUID nuevo nace con el siguiente mensaje). Pendiente de decidir con uso real: si el historial debe ocultar las conversaciones sin ningún turno exitoso (saludos, bloqueos, errores), o mostrarlas todas (lo que hace el plan hoy).
2. **D4** (nombre con una llamada extra al modelo que no aparece en las estadísticas del Benchmark; la alternativa sin costo es usar siempre el comienzo de la primera consulta como nombre).
3. **D5** (seudónimo en la auditoría y cambio de "anónimo" a "sin referencia al usuario").
4. **D6/D7** (sin ventana de tiempo ni expiración; las conversaciones crecen indefinidamente hasta que haya depuración).
5. **D8** (renombrar incluido por el lápiz de la imagen).
