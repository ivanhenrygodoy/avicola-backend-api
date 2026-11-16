# Diseño: Sidebar de navegación, roles, permisos por módulo y gestión de usuarios

Fecha: 2026-11-12 · Repos: `avicola-backend-api` (Laravel 11, PostgreSQL) y `avicola-frontend` (Vue 3 + Pinia + Tailwind)

## 1. Intención y por qué

Hoy la navegación está en la barra superior (`AppHeader.vue`, repetida en 6 vistas) y **todos los usuarios ven y pueden usar todo**: el backend solo exige `auth:sanctum`, `mnt_usuarios` no tiene rol, y hay un único usuario («Administrador de Granja»). El usuario quiere empezar a **repartir responsabilidades**: que un operario trabaje la granja sin acceso al Benchmark de la IA, y que un superAdmin administre quién ve qué.

Se pidió:

1. Un **sidebar a la izquierda** con icono de hamburguesa para abrirlo u ocultarlo. Contiene los módulos que hoy tiene la barra superior; la barra superior los **pierde**. Conserva estilos, iconos y reglas de diseño.
2. **Roles**: `superAdmin` accede a todo; `operario` accede a todo **menos al Benchmark** (Dashboard, Instalaciones, Inventario de Aves, Producción, Gastos y el chat sí).
3. Que la restricción sea **real**: el servidor responde 403, no solo se oculta la opción.
4. **Tablas** para repartir responsabilidades, con la asignación **administrable** desde una pantalla de **gestión de permisos exclusiva del superAdmin**.
5. Que el superAdmin **cree usuarios** y les asigne el rol; el sistema les **envía un correo con credenciales iniciales**; al primer ingreso se les **pide volver a iniciar sesión y se les ofrece cambiar la contraseña**, con la opción de quedarse con la temporal.
6. En el sidebar, Benchmark aparece **desglosado bajo una ruta padre «Asistente IA»**.
7. El fondo del sidebar lo propone el diseñador (por ejemplo el icono de la gallina), respetando `DESIGN.md` y las skills. **El ajuste visual fino se hace al terminar** (el usuario lo pidió así; no hay maquetas previas).

## 2. Objetivos, no-objetivos y anti-objetivos

**Objetivos**
- Menú lateral colapsable, alimentado por los permisos del usuario que vienen del backend.
- Modelo de roles, módulos y permisos en tablas; seeder con los dos roles iniciales y los módulos actuales.
- Middleware que protege las rutas de la API por módulo (403 para quien no tiene permiso).
- Pantalla de gestión de permisos (rol × módulo) y de usuarios (crear, asignar rol, activar/desactivar), solo para superAdmin.
- Creación de usuario con contraseña temporal aleatoria enviada por correo; primer ingreso con aviso y cambio opcional de contraseña.

**No-objetivos (esta fase)**
- Permisos por acción (ver / crear / editar / eliminar): el permiso es **por módulo**, de acceso sí o no. Queda para otra fase.
- Crear o editar **roles** desde la interfaz (solo se asignan módulos a los dos roles que existen). Los roles se siembran.
- Recuperación de contraseña por correo («olvidé mi contraseña»), verificación de correo, doble factor.
- Auditoría de quién cambió qué permiso.
- Caducidad de la contraseña temporal (decisión D2: no caduca).
- Las rutas de datos que no son un módulo propio (catálogos, `granjas`, `me`) quedan para cualquier usuario autenticado.

**Anti-objetivos (fracaso aunque «funcione»)**
- Que un operario obtenga datos del Benchmark por la API aunque el menú no se los muestre.
- Que un usuario se pueda **quitar el acceso a sí mismo o dejar el sistema sin ningún superAdmin** (el último superAdmin no puede degradarse, desactivarse ni perder permisos).
- Que un operario llegue a la gestión de permisos o de usuarios por la API o por URL.
- Que la contraseña temporal quede registrada en el log, en la respuesta de la API o en la base de datos en texto plano.
- Que un fallo del correo deje un usuario creado sin forma de que el superAdmin se entere (ver D6).
- Que el sidebar rompa las 6 vistas existentes o los modales (z-index) o el chat flotante.
- Perder la sesión o los datos del usuario actual al migrar.

## 3. Restricciones (reglas de cada repo)

Backend (`.agents/skills/avicola-backend`): tablas con prefijo `mnt_` (negocio) o `ctl_` (catálogos); claves `id_<entidad>`; controladores delgados; FormRequests para autorización y validación (uno por acción); servicios con una sola responsabilidad; respuestas JSON `{success, message, data}`; pruebas obligatorias; comandos de test **solo** con `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test` (un `php artisan test` pelado vacía la BD de desarrollo).

Frontend (`.agents/skills/avicola-frontend`, `DESIGN.md`): paleta bosque `#244B36`, salvia `#6F9678`, crema `#F5F3EA`, verdoso `#26332B`, borde `#E4E7DF`; fuente del sistema (`system-ui`); formularios con `FormField`; modales con `BaseModal`/`BaseConfirmModal`; el backdrop nunca lleva `scale-*`; reutilizar `PageHeader`, `DataTable`, `StatusBadge`, `RowActionButton`, `EmptyState`, `LoadingState`, `PillTabs`; iconos `lucide-vue-next`; sin estética genérica de IA. No alterar componentes globales ya establecidos sin consultar.

Datos: la BD de desarrollo tiene datos reales; **solo migraciones aditivas** (`migrate:status` y `--pretend` antes), nunca `migrate:fresh/refresh`, sin INSERT/DELETE manuales. El seeder de roles/módulos debe ser **idempotente** (`updateOrCreate`).

Repositorio: no se hace commit sin orden del usuario y nunca se agrega a Claude como coautor; el usuario hace el `push`.

## 4. Decisiones del usuario (tal cual las dio)

- **D1. Alcance de permisos:** `superAdmin` accede a todo. `operario` accede a todo **menos al Benchmark**; sí a Dashboard, módulos básicos y chat.
- **D2. Contraseña temporal opcional:** al primer ingreso el usuario puede **cambiarla o quedarse con esa**. No caduca.
- **D3. Restricción real:** el servidor **niega el acceso** (no basta con ocultar).
- **D4. Asignación administrable por superAdmin:** una sección de **gestión de permisos**, visible y usable solo por superAdmin.
- **D5. Gestión de usuarios:** el superAdmin crea usuarios, les asigna el rol de operario, y el sistema les envía un **correo con credenciales iniciales**; el sistema **pide volver a iniciar sesión** y ofrece cambiar la contraseña.
- **D6 (usuario).** Benchmark va bajo una **ruta padre «Asistente IA»** desglosada, **en el sidebar**.
- **D7. Fondo del sidebar:** lo propone el diseñador; el ajuste visual se hace al final.
- **D8. Los módulos base del operario no se quitan:** el superAdmin no puede quitarle módulos al operario; Dashboard, Instalaciones, Aves, Producción, Gastos y el chat son fijos. La gestión de permisos solo **otorga** módulos opcionales (hoy únicamente el Benchmark). Se descarta así el escenario de un operario sin Gastos que consulta gastos por el chat.
- **D9. El correo de credenciales sale por una cola** con un worker que lo envía por debajo al crear el usuario (ver R21).

## 5. Decisiones del diseñador (el usuario puede cambiarlas)

- **R1. Modelo de datos** (todas con prefijo `mnt_`; ver §7): `mnt_roles`, `mnt_modulos` (con jerarquía padre/hijo para «Asistente IA > Benchmark»), `mnt_rol_modulo` (permisos) y una columna `id_rol` en `mnt_usuarios`, más `debe_cambiar_password`, `activo` y el estado del envío de credenciales (`credenciales_estado`, `credenciales_error`, R21). Se usa `mnt_` y no `ctl_` porque la asignación la edita el superAdmin (es dato de negocio, no un catálogo fijo).
- **R2. La tabla de módulos asocia cada módulo con sus rutas de la API**, no con una lista de controladores. Una columna `clave` estable (`dashboard`, `instalaciones`, `aves`, `produccion`, `gastos`, `asistente`, `benchmark`, `usuarios`, `permisos`) es lo que usa el middleware `modulo:<clave>`. Las rutas del frontend y los iconos son metadatos del módulo (`ruta`, `icono`, `orden`, `exacto`), así el sidebar se arma solo.
- **R3. Middleware `modulo:<clave>`** (`EnsureModuloPermitido`): carga el rol del usuario con sus módulos (una consulta, con caché por petición), responde **403** con el JSON estándar si no tiene el módulo. Se aplica por grupos de rutas: dashboard → `dashboard`; casitas/divisiones/granjas de instalaciones → `instalaciones`; aves/pesos/observaciones → `aves`; alimentacion/huevos → `produccion`; gastos → `gastos`; `asistente/chat` y conversaciones → `asistente`; `asistente/benchmark/*` → `benchmark`; usuarios y permisos → `usuarios`/`permisos`. Los catálogos compartidos que usan varios módulos (por ejemplo `catalogos/*`, `aves/catalogos`) exigen solo autenticación: ver «puntos abiertos» §11.
- **R4. superAdmin con acceso total por regla, no por filas:** el rol `superAdmin` se marca con una columna `es_super` y el middleware lo deja pasar siempre. Así un módulo nuevo nunca deja al superAdmin fuera, y borrar filas de `mnt_rol_modulo` no puede dejar el sistema sin administrador.
- **R5. Qué recibe el frontend:** `login` y `me` devuelven, además del usuario, su `rol` y la lista `modulos` (árbol: clave, nombre, ruta, icono, hijos) que puede ver, y `debe_cambiar_password`. El sidebar solo renderiza esa lista. La ocultación en el frontend es solo comodidad; la seguridad es R3.
- **R6. Guardia de rutas en el frontend:** `router.beforeEach` compara la ruta con los módulos del usuario; si no la tiene, redirige al Dashboard (o, si tampoco tiene Dashboard, al primer módulo que sí tenga). Las rutas nuevas (`/usuarios`, `/permisos`) llevan `meta.modulo`.
- **R7. Sidebar:** componente `AppSidebar.vue` dentro de un layout común (`AppLayout.vue`) que reemplaza el `AppHeader` repetido en las 6 vistas. Estado abierto/colapsado en un store (`useNavegacionStore`) con `localStorage` (con try/catch); en pantallas pequeñas (< `lg`) el sidebar es un panel superpuesto con backdrop que se cierra al navegar. La barra superior conserva logo/título, la hamburguesa, el nombre del usuario y «Cerrar sesión». «Asistente IA» es un grupo expandible con «Benchmark» dentro; se auto-expande si la ruta activa es hija. Los estilos de ítem (activo/inactivo, iconos `w-4 h-4`, `text-salvia`) se copian de los actuales del header.
- **R8. Fondo del sidebar (propuesta, ajustable al final):** superficie `bg-white`/`crema-surface` con borde derecho `border-borde`; la gallina (`GallinaIcon`) como marca de agua grande en la esquina inferior a baja opacidad (`text-bosque/5`), sin interferir con el texto; encabezado del panel con el logo. Se valida visualmente en la fase de ajuste.
- **R9. Creación de usuario (el modo de envío lo reemplaza R21):** el superAdmin indica nombre, correo y rol (hoy solo `operario`; el selector lista los roles existentes). El servidor genera una contraseña temporal aleatoria de 12 caracteres (`Str::password`), la guarda **solo hasheada**, marca `debe_cambiar_password = true` y envía el correo (cola `database` ya configurada, para no retrasar la respuesta). La contraseña en claro solo existe en memoria durante el envío: nunca en la respuesta de la API, nunca en el log.
- **R10. Correo (el modo de envío y de fallo los reemplaza R21):** una `Mailable` (`CredencialesIniciales`) en español con el correo del usuario, la contraseña temporal, la URL del sistema y el aviso de que puede cambiarla. **El transporte lo define el usuario en el `.env`** (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`); el código solo usa la configuración estándar de Laravel y no fija ningún servidor. Hoy `.env` tiene `MAIL_MAILER=log`, que escribiría la contraseña temporal en `laravel.log`: por eso la implementación **se niega a enviar credenciales con el mailer `log` o `array`** (el job deja `credenciales_estado: 'fallido'` con un aviso claro, sin escribir la clave en ningún log). Para pruebas locales sirve el contenedor `mailpit` (SMTP `mailpit:1025`). Si el envío falla, el usuario **sí queda creado** y queda marcado `fallido` (visible en la lista) para que el superAdmin use «Reenviar credenciales» (genera otra temporal y la envía).
- **R11. Primer ingreso:** si `debe_cambiar_password` es verdadero, tras el login se muestra un modal «Cambia tu contraseña» con dos acciones: **Cambiar contraseña** (formulario, requiere la actual) y **Mantener esta contraseña** (marca la bandera en falso sin cambiar nada). Cualquiera de las dos cierra el aviso para siempre. Al cambiarla se invalidan los demás tokens del usuario (cierra otras sesiones) y se le pide **volver a iniciar sesión**, como pidió el usuario.
- **R12. Último superAdmin protegido:** una regla de servicio impide desactivar, degradar o quitar permisos a quien sea el único superAdmin activo; también impide que un usuario se desactive o se cambie el rol a sí mismo.
- **R13. Usuarios desactivados:** el campo `activo = false` bloquea el login y revoca sus tokens al desactivarlo. Nada se borra (consistente con la regla del proyecto: eliminar solo cambia estado).
- **R15. Identidad del usuario (verificado en el código):** el usuario inicia sesión con su **correo** (`mnt_usuarios.email`, ya existe y es único; el login lo valida como `email`). El correo de credenciales va a esa dirección, y el correo del usuario actual (`admin@granja.local`) no es una dirección real: probar «reenviar credenciales» o el primer ingreso se hace con usuarios nuevos creados con un correo de prueba (en desarrollo lo atrapa `mailpit`).
- **R16. Cambiar el rol o desactivar a un usuario revoca sus tokens** (cierra sus sesiones): así el menú y la API quedan alineados con el nuevo rol sin depender de que recargue. Cambiar los **módulos de un rol** (matriz de permisos) no revoca tokens: el servidor aplica el cambio en la siguiente petición (R3) y el frontend lo recoge por la regla R17.
- **R17. Un 403 a mitad de sesión** (los permisos cambiaron mientras el usuario trabajaba): un interceptor de la API del frontend vuelve a pedir `me`, actualiza el menú y, si la ruta actual ya no está permitida, lo lleva al Dashboard con un aviso «Ya no tienes acceso a ese módulo» (nunca muestra el error crudo).
- **R18. El aviso de primer ingreso es suave, no bloqueante:** se muestra al iniciar sesión y se puede cerrar sin elegir (Escape o fuera del modal); reaparece en el siguiente inicio de sesión hasta que el usuario elija una de las dos opciones. Ninguna ruta de la API se restringe por `debe_cambiar_password`. Es lo que el usuario pidió («opcional»).
- **R19. `POST auth/password/mantener` no pide la contraseña**: solo apaga la bandera para el propio usuario autenticado, no cambia ningún secreto, y un token robado ya tiene acceso a todo lo que ese usuario puede hacer.
- **R20. Chat sin el módulo `asistente`:** el widget flotante no se muestra y sus rutas responden 403. Sus conversaciones previas se conservan (nada se borra) pero no son accesibles hasta que recupere el módulo.
- **R21. Correo por cola, con la contraseña generada dentro del job (D9):** el correo sale por la cola `database` (ya configurada) con un worker propio: servicio `queue` en `docker-compose.yml` que corre `php artisan queue:work --tries=3`. Para que la contraseña en claro no quede en `jobs.payload` ni en `failed_jobs`, el job `EnviarCredencialesIniciales` recibe **solo el `id_usuario`**. Al crear (o reenviar), el servicio deja la cuenta con una clave aleatoria que nadie conoce, `debe_cambiar_password = true` y `credenciales_estado = 'pendiente'`, y despacha el job; el job genera la temporal (`Str::password(12, symbols: false)`), guarda solo el hash, envía la `Mailable` y marca `enviado`. Tras agotar los reintentos marca `fallido` con un `credenciales_error` corto y sin la contraseña. Con el mailer `log` o `array` el job no envía y deja `fallido` con el aviso «El servidor de correo no está configurado.». La API responde `credenciales_estado: 'pendiente'` (reemplaza `correo_enviado`); la lista de usuarios muestra el estado y «Reenviar credenciales» (genera otra temporal) siempre disponible, así el superAdmin se entera de un fallo. El job usa `WithoutOverlapping` por usuario y un `timeout` para que un SMTP colgado no bloquee el worker. El worker no recarga código: tras tocar un job, `docker compose restart queue`.
- **R22. Módulos base, opcionales y solo-superAdmin (D8):** `mnt_modulos.base` marca los módulos que todo rol no super recibe siempre (`dashboard`, `instalaciones`, `aves`, `produccion`, `gastos`, `asistente`); `solo_super` marca `usuarios` y `permisos`; el único opcional es `benchmark`. `PUT permisos/{rol}` recibe solo los módulos opcionales a otorgar: los base se conservan siempre (la matriz los muestra bloqueados) y un `solo_super` da 422. Consecuencia: el hueco «chat con datos de un módulo quitado» no existe en esta fase, porque el chat solo expone módulos base que el operario siempre tiene; si una fase futura permite quitar módulos base, cada herramienta del asistente deberá exigir su módulo. Un 403 a mitad de sesión (R17) solo puede venir de quitarle el Benchmark, desactivarlo o cambiarle el rol.
- **R14. Migración del usuario actual:** `id_rol` se agrega nullable, el seeder crea los roles y la migración de datos asigna `superAdmin` a los usuarios existentes (hoy uno). Después de poblarlo, el modelo trata `id_rol` nulo como sin permisos (nunca como superAdmin).

## 6. Comportamiento

1. **Login:** el usuario inicia sesión; la respuesta trae rol, módulos y `debe_cambiar_password`. El sidebar se arma con esos módulos.
2. **Navegación:** hamburguesa abre/oculta el sidebar; la elección se recuerda. «Asistente IA» muestra «Benchmark» debajo solo si el rol tiene el módulo `benchmark`; si no tiene ningún hijo visible, el grupo no aparece. El chat flotante depende del módulo `asistente`.
3. **Operario hacia Benchmark:** por el menú no aparece; por URL lo redirige al Dashboard; por API recibe 403 `{"success":false,"message":"No tienes permiso para acceder a este módulo."}`.
4. **Gestión de permisos (superAdmin):** una tabla rol × módulo con interruptores; el superAdmin otorga o retira los módulos **opcionales** (hoy, Benchmark) al operario; los módulos base aparecen fijos y no se pueden apagar (R22). Los cambios se aplican en el siguiente login o recarga del usuario afectado (el servidor los respeta de inmediato; el menú se actualiza al recargar `me`).
5. **Gestión de usuarios (superAdmin):** listado con rol y estado; crear (envía correo), cambiar rol, activar/desactivar, reenviar credenciales.
6. **Primer ingreso:** modal con las dos opciones (R11). Si cambia la contraseña, se cierran las sesiones y vuelve al login.
7. **Operario que pide algo del chat de un módulo restringido:** no aplica (D8, R22): el operario nunca pierde módulos base y ninguna de las 6 herramientas del asistente toca el Benchmark (verificado).

## 7. Datos

Tablas nuevas (migraciones aditivas):
- `mnt_roles`: `id`, `clave` (única: `super_admin`, `operario`), `nombre`, `es_super` (bool), `timestamps`.
- `mnt_modulos`: `id`, `id_modulo_padre` (nullable, FK a sí misma), `clave` (única), `nombre`, `ruta` (nullable, ruta del frontend), `icono` (nombre lucide), `orden`, `exacto` (bool), `activo` (bool), `solo_super` (bool), `base` (bool), `timestamps`.
- `mnt_rol_modulo`: `id`, `id_rol` y `id_modulo` (únicos juntos; `restrictOnDelete`), `timestamps`.
- `mnt_usuarios` (columnas nuevas): `id_rol` (FK nullable, `restrictOnDelete`), `activo` (bool, defecto verdadero), `debe_cambiar_password` (bool, defecto falso), `credenciales_estado` (string nullable: `pendiente`, `enviado`, `fallido`), `credenciales_error` (string nullable, corto, sin contraseña).

El seeder (idempotente) crea los 2 roles, los módulos —`dashboard`, `instalaciones`, `aves`, `produccion`, `gastos`, el grupo `asistente` («Asistente IA», sin ruta) con hijo `benchmark`, y `usuarios`/`permisos` (solo superAdmin por regla R4)— marca como `base` los módulos básicos y el chat (`asistente`), `benchmark` como opcional y `usuarios`/`permisos` como `solo_super`, y asigna al operario los módulos base (no `benchmark`, `usuarios` ni `permisos`).

## 8. API (bajo `auth:sanctum`)

- `POST auth/login`, `GET auth/me`: amplían la respuesta (R5).
- `POST auth/password` (cambiar contraseña, exige la actual) y `POST auth/password/mantener` (cierra el aviso).
- `GET/POST usuarios`, `PATCH usuarios/{id}` (rol, activo), `POST usuarios/{id}/reenviar-credenciales` → módulo `usuarios`. El `POST` responde `credenciales_estado: 'pendiente'` y el correo sale por la cola (R21).
- `GET permisos` (matriz rol × módulo) y `PUT permisos/{rol}` (lista de módulos opcionales a otorgar; los base se conservan, R22) → módulo `permisos`.
- Todas las demás rutas existentes quedan envueltas por `modulo:<clave>` (R3).

## 9. Pruebas

Obligatorias (backend, base aislada): 403 por rol para cada grupo de rutas (operario → benchmark/usuarios/permisos denegados; dashboard/aves/gastos permitidos); superAdmin pasa en todas; `id_rol` nulo no accede a nada; cambiar permisos tiene efecto inmediato en la API; los módulos base no se pueden quitar; último superAdmin protegido (no se puede desactivar/degradar); autodesactivación bloqueada; creación de usuario: contraseña hasheada, `debe_cambiar_password` verdadero, el job se encola con solo el `id_usuario` (la contraseña no aparece en el payload) y al ejecutarse guarda el hash, `Mail::fake` envía una sola vez y marca `enviado`; la contraseña **no** aparece en la respuesta ni en el log; con mailer `log`/`array` o fallo del transporte el usuario queda creado con `credenciales_estado: 'fallido'`; usuario desactivado no puede iniciar sesión y pierde tokens; cambio de contraseña invalida otros tokens; seeder idempotente (se ejecuta dos veces sin duplicar).
Frontend: no hay corredor de pruebas; se verifica con `vite build` y recorrido en el navegador (operario y superAdmin, escritorio y móvil, sidebar abierto/colapsado, modales sobre el sidebar, chat flotante).

## 10. Qué queda para quien construya (decide sin consultar)

Nombres exactos de clases y rutas del frontend, texto y diseño del correo, mensajes de error, ancho del sidebar, animación, orden dentro de la matriz de permisos, tamaño de la marca de agua (R8), si el layout nuevo se aplica con una vista envolvente o con rutas anidadas, y el detalle de cómo cachear los permisos por petición.

## 11. Puntos abiertos para el usuario

Verificado en el código al revisar la spec: los catálogos compartidos (`catalogos/*`, `aves/catalogos`) y el Dashboard no leen datos del Benchmark, así que dejar los catálogos abiertos a cualquier usuario autenticado no filtra nada.

Se asumieron (cámbialas si no):
1. Los **catálogos compartidos** (`catalogos/*`, `aves/catalogos`, `granjas`) quedan accesibles a cualquier usuario autenticado, porque los usan varios módulos.
2. Hoy solo existe el rol `operario` además del superAdmin; **crear roles nuevos desde la interfaz** queda para otra fase.
3. **Correo:** el usuario configura el SMTP en el `.env`; la entrega solo implementa el envío y documenta las variables necesarias en el README. Hasta que lo configure, crear usuarios funciona pero el correo no sale (R10).
4. El módulo `usuarios` y `permisos` son **solo del superAdmin** y no se pueden asignar a un operario desde la matriz.
5. **D8:** los módulos base del operario no se pueden quitar; la matriz solo otorga el Benchmark. El hueco del chat queda descartado en esta fase (R22).
