# Sidebar, roles, permisos por módulo y gestión de usuarios — Plan de implementación

**Estado:** Finalizado con éxito

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sidebar lateral colapsable alimentado por permisos por módulo, con roles `super_admin`/`operario`, protección 403 en la API, pantallas de gestión de permisos y usuarios (solo superAdmin) y alta de usuarios con credenciales por correo.

**Architecture:** Tablas `mnt_roles`, `mnt_modulos` (jerarquía padre/hijo) y `mnt_rol_modulo`; el rol `es_super` pasa siempre por regla. Un middleware `modulo:<clave>` envuelve los grupos de rutas; `login`/`me` devuelven rol, árbol de módulos y claves permitidas, y el frontend arma el sidebar y la guardia del router con eso. La seguridad es del servidor; el frontend solo oculta por comodidad.

**Tech Stack:** Laravel 11 / PHP 8.3 / PostgreSQL 16 / Sanctum / PHPUnit 12 (backend, Docker); Vue 3 + Pinia + Tailwind 3 + lucide-vue-next (frontend, sin corredor de pruebas: `vite build` + navegador).

**Spec:** `docs/superpowers/specs/2026-11-12-sidebar-roles-permisos-design.md` (decisiones D1–D9, R1–R22). Aprobada por el usuario; D8 (módulos base fijos) y D9 (correo por cola) se agregaron el 2026-11-13.

## Global Constraints

- Tablas con prefijo `mnt_`, claves `id_<entidad>`; controladores delgados; un FormRequest por acción; respuestas `{success, message, data}`; errores de regla de negocio en 422.
- Tests backend **solo**: `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test [--filter=X]`. Un `php artisan test` pelado vacía la BD de desarrollo.
- BD de desarrollo con datos reales: migraciones **solo aditivas**; antes de migrar, `migrate:status` y `migrate --pretend`; nunca `migrate:fresh/refresh`; sin INSERT/DELETE manuales. Seeder idempotente (`updateOrCreate`).
- Pint solo sobre los archivos tocados. Frontend: paleta bosque `#244B36`, salvia `#6F9678`, crema `#F5F3EA`, verdoso `#26332B`, borde `#E4E7DF`; fuente `system-ui`; `FormField`, `BaseModal`/`BaseConfirmModal`, `PageHeader`, `DataTable`, `StatusBadge`, `RowActionButton`, `EmptyState`, `LoadingState`; el backdrop de modales nunca lleva `scale-*`; no alterar componentes globales existentes; iconos `lucide-vue-next`.
- **No hacer commit** hasta que el usuario lo ordene, y nunca agregar a Claude como coautor. El usuario hace el `push`. Ningún paso de este plan commitea.
- El SMTP lo configura el usuario en `.env`; el código no fija servidor (D/R10). El `.env` llega al worker por el bind mount `.:/var/www/html`.
- Tras crear o modificar un job, reiniciar el worker: `docker compose restart queue`.
- Un `php artisan serve` con `vendor/` en volumen: el contenedor es `avicola_api`; los comandos usan `docker compose exec -T api ...`.

## Rulings del plan (decididos al planificar; el ledger los hereda)

- **Ruling 1 — correo por cola con worker propio (D9/R21):** hoy `QUEUE_CONNECTION=database` y las tablas `jobs`/`failed_jobs` existen, pero ningún proceso consume la cola. Se agrega un servicio `queue` a `docker-compose.yml` (`queue:work --tries=3`). El job `EnviarCredencialesIniciales` recibe **solo el `id_usuario`** y genera la contraseña temporal dentro del worker: así la clave en claro nunca queda en `jobs.payload` ni en `failed_jobs`. El estado del envío vive en `mnt_usuarios.credenciales_estado` (`pendiente`/`enviado`/`fallido`) para que el superAdmin vea fallos. Costo: un contenedor más, y el worker no recarga código (`docker compose restart queue` tras tocar un job).
- **Ruling 2 — la factory crea superAdmin por defecto:** los 357 tests existentes usan `User::factory()->create()` sin rol y ahora recibirían 403. `UserFactory` asigna `super_admin` por defecto (rol sembrado por la migración de datos) y añade `->operario()` y `->sinRol()`. Costo si está mal: ninguno en producción; solo afecta datos de prueba.
- **Ruling 3 — columnas `solo_super` y `base` en `mnt_modulos` (R22):** `solo_super` marca `usuarios`/`permisos` (nunca asignables al operario); `base` marca los módulos que el operario siempre conserva (`dashboard`, `instalaciones`, `aves`, `produccion`, `gastos`, `asistente`). El único módulo opcional es `benchmark`. La matriz solo otorga opcionales (D8). Costo: dos columnas más.
- **Ruling 4 — la migración de datos llama al seeder:** `RolesYModulosSeeder` es idempotente; una migración final lo ejecuta y asigna `super_admin` a los usuarios con `id_rol` nulo, para que el sistema no quede sin acceso tras desplegar sin `db:seed`.
- **Ruling 5 — `me` y `login` devuelven también `permisos` (lista plana de claves):** el árbol de `modulos` no basta porque `asistente` es a la vez el grupo del menú y el permiso del chat. El widget del chat se decide con `permisos`.
- **Ruling 6 — usuario desactivado o sin rol:** el middleware `modulo` responde 403 también si `activo` es falso (defensa extra además de revocar tokens), y `id_rol` nulo no accede a nada.

## Review Focus

Entradas/condiciones que la spec implica y ninguna tarea ejercita por sí sola:

- Los 357 tests existentes siguen pasando (Ruling 2): se corre la suite completa al final de cada tarea backend.
- Un usuario con `id_rol` nulo, o desactivado con token aún vivo, recibe 403 en todo (Tarea 2).
- El único superAdmin no puede desactivarse, degradarse ni autoeditarse el rol (Tarea 5); el superAdmin tampoco puede quitarse a sí mismo el acceso por la matriz (el rol super no se edita).
- Un correo con mailer `log`/`array` nunca escribe la contraseña temporal en ningún log y el usuario se crea igual (Tarea 5).
- Operario con la página del Benchmark abierta cuando le quitan el permiso: el 403 lo lleva al Dashboard con aviso y no deja pantalla rota (Tarea 9).
- El sidebar no queda por encima de los modales (`BaseModal` usa `z-[9999]`) ni tapa el chat flotante (`z-40`) ni rompe las 6 vistas (Tarea 8).
- La contraseña temporal no aparece en `jobs.payload` ni en `failed_jobs` (Tarea 5): se revisa el payload del job encolado.
- Un job fallido o con mailer `log`/`array` deja `credenciales_estado = 'fallido'` visible en la lista de usuarios, sin contraseña en el error (Tareas 5 y 10).
- Un reenvío mientras hay otro job pendiente del mismo usuario no deja dos temporales válidas (`WithoutOverlapping`, Tarea 5).
- La matriz nunca permite quitar un módulo base al operario ni otorgar `usuarios`/`permisos` (Tarea 4).

---

## Backend

### Task 1: Roles, módulos, permisos y columnas de usuario

**Files:**
- Create: `database/migrations/2026_11_12_110000_create_mnt_roles_table.php`, `..._110100_create_mnt_modulos_table.php`, `..._110200_create_mnt_rol_modulo_table.php`, `..._110300_add_rol_y_estado_a_mnt_usuarios_table.php` (incluye `credenciales_estado`, `credenciales_error`), `..._110400_asignar_super_admin_a_usuarios_existentes.php`
- Create: `app/Models/Rol.php`, `app/Models/Modulo.php`, `database/seeders/RolesYModulosSeeder.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`, `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/RolesYModulosTest.php`

**Interfaces:**
- Produces: `Rol` (`mnt_roles`: `clave` única, `nombre`, `es_super`; constantes `CLAVE_SUPER_ADMIN='super_admin'`, `CLAVE_OPERARIO='operario'`; `modulos(): BelongsToMany` por `mnt_rol_modulo` con `id_rol`/`id_modulo`). `Modulo` (`mnt_modulos`: `id_modulo_padre` nullable, `clave` única, `nombre`, `ruta` nullable, `icono`, `orden`, `exacto`, `activo`, `solo_super`, `base`; `padre()`, `hijos()`). `User`: `rol(): BelongsTo`, `esSuperAdmin(): bool`, `puedeAcceder(string $clave): bool`, `clavesPermitidas(): array<int,string>` (superAdmin → todas las activas; `id_rol` nulo o `activo` falso → `[]`); `$fillable` suma `id_rol`, `activo`, `debe_cambiar_password`, `credenciales_estado`, `credenciales_error`. `UserFactory`: por defecto `super_admin`; estados `operario()`, `sinRol()`, `inactivo()`.
- Seeder: módulos `dashboard`, `instalaciones`, `aves`, `produccion`, `gastos`, `asistente` (grupo «Asistente IA», sin ruta) con hijo `benchmark` («Benchmark», `/benchmark`), `usuarios` y `permisos` (`solo_super`). Rutas, iconos lucide (`LayoutDashboard`, `Home`, `Layers`, `Egg`, `DollarSign`, `Bot`, `Activity`, `Users`, `ShieldCheck`) y `orden` como en el header actual (`/`, `/instalaciones`, `/aves`, `/produccion`, `/gastos`, `/benchmark`; solo `dashboard` con `exacto`). `base = true` en `dashboard`, `instalaciones`, `aves`, `produccion`, `gastos`, `asistente`; `solo_super = true` en `usuarios`, `permisos`; `benchmark` es el único opcional. El operario recibe los módulos base (no `benchmark`, `usuarios`, `permisos`). El seeder, al re-ejecutarse, **no quita** `benchmark` a un operario al que el superAdmin ya se lo otorgó (solo asegura los base).

- [ ] **Step 1: Escribir los tests que fallan** en `RolesYModulosTest`: `test_el_seeder_es_idempotente` (dos corridas → 2 roles y 9 módulos, sin duplicados); `test_operario_no_tiene_benchmark_usuarios_ni_permisos` (`clavesPermitidas()` de un operario contiene `dashboard`, `asistente`, `gastos` y no `benchmark`/`usuarios`/`permisos`); `test_super_admin_tiene_todos_los_modulos_activos`; `test_usuario_sin_rol_no_tiene_ningun_modulo`; `test_usuario_inactivo_no_tiene_ningun_modulo`; `test_el_seeder_no_quita_benchmark_si_ya_fue_otorgado`; `test_la_migracion_asigna_super_admin_a_usuarios_existentes` (crear un usuario con `id_rol` nulo, ejecutar la lógica de la migración 110400 y comprobar que queda `super_admin`).
- [ ] **Step 2: Ejecutar** `--filter=RolesYModulosTest`. Expected: FAIL (clases/tablas inexistentes).
- [ ] **Step 3: Implementar** migraciones, modelos, seeder, `User` y `UserFactory`. La migración 110400 llama a `RolesYModulosSeeder` y asigna `super_admin` a los `id_rol` nulos (Ruling 4); `down()` de las migraciones no borra datos de negocio.
- [ ] **Step 4: Ejecutar la suite completa** (comando aislado). Expected: 357 + nuevos pasan (Ruling 2: nada existente se rompe).
- [ ] **Step 5: Aplicar a la BD de desarrollo con cuidado:** `docker compose exec -T api php artisan migrate:status`, luego `migrate --pretend`, revisar que solo crea tablas/columnas y la asignación de rol, y recién entonces `migrate`. Verificar con `SELECT` que `admin@granja.local` quedó con `super_admin` y que los conteos de negocio (aves 14, gastos 5, huevos 8, alimentación 3) no cambiaron.

### Task 2: Middleware `modulo:<clave>` y protección de rutas

**Files:**
- Create: `app/Http/Middleware/EnsureModuloPermitido.php`
- Modify: `bootstrap/app.php` (alias `modulo`), `routes/api.php`
- Test: `tests/Feature/ProteccionPorModuloTest.php`

**Interfaces:**
- Consumes: `User::puedeAcceder`, `User::activo`.
- Produces: `EnsureModuloPermitido::handle(Request $request, Closure $next, string $clave)`; 403 con `{"success":false,"message":"No tienes permiso para acceder a este módulo."}`.
- Mapa de grupos: `dashboard/*`→`dashboard`; `granjas`, `casitas`, `divisiones` (y sus `tipos`/`toggle`)→`instalaciones`; `aves`, `pesos`, `observaciones`→`aves`; `alimentacion`, `huevos`→`produccion`; `gastos`→`gastos`; `asistente/chat` y `asistente/conversaciones*`→`asistente`; `asistente/benchmark/*`→`benchmark`. **Los `catalogos/*`, `aves/catalogos|especies|sexos|propositos`, `alimentos/tipos`, `gastos/categorias`, `instalaciones/tipos`, `casitas/tipos` quedan solo con `auth:sanctum`** (spec §11.1, verificado: no leen datos del Benchmark).

- [ ] **Step 1: Escribir los tests que fallan** con un `#[DataProvider]` de `[uri, clave]` sobre los `GET` índice (`dashboard/resumen`, `casitas`, `aves`, `huevos`, `alimentacion`, `gastos`, `asistente/conversaciones`, `asistente/benchmark/estadisticas`): `test_super_admin_accede_a_todo` (ningún 403); `test_operario_accede_a_todo_menos_benchmark` (200 en los demás, 403 en benchmark con el mensaje exacto); `test_usuario_sin_rol_recibe_403_en_todo`; `test_usuario_inactivo_con_token_vivo_recibe_403`; `test_los_catalogos_compartidos_siguen_abiertos_a_un_operario`; `test_sin_autenticar_recibe_401_no_403`.
- [ ] **Step 2: Ejecutar** `--filter=ProteccionPorModuloTest`. Expected: FAIL (el operario hoy accede a benchmark).
- [ ] **Step 3: Implementar** el middleware, registrar el alias y envolver los grupos de `routes/api.php` según el mapa.
- [ ] **Step 4: Suite completa.** Expected: todo en verde; si algún test existente falla con 403, la causa es la factory (Ruling 2), no se parchea el test.

### Task 3: Login, `me` y bloqueo de usuarios desactivados

**Files:**
- Create: `app/Services/Acceso/PermisosService.php`
- Modify: `app/Http/Controllers/Api/V1/AuthController.php`, `tests/Feature/AuthTest.php` (casos nuevos al final)
- Test: `tests/Feature/AuthPermisosTest.php`

**Interfaces:**
- Produces: `PermisosService::clavesPermitidas(User): array`, `PermisosService::arbolPara(User): array` (nodos `{clave, nombre, ruta, icono, exacto, hijos: [...]}` ordenados por `orden`; un grupo sin ruta aparece solo si tiene algún hijo visible). `login` y `me` devuelven `data.user` con `id, name, email, rol {clave, nombre}, modulos (árbol), permisos (lista plana, Ruling 5), debe_cambiar_password`.

- [ ] **Step 1: Tests que fallan:** `test_login_devuelve_rol_arbol_y_permisos` (operario: árbol sin `benchmark` y sin grupo `asistente` visible si no tiene hijos; `permisos` incluye `asistente`); `test_el_grupo_asistente_ia_aparece_para_super_admin_con_benchmark_dentro`; `test_me_devuelve_los_mismos_datos`; `test_usuario_inactivo_no_puede_iniciar_sesion` (422, mensaje «Tu cuenta está desactivada. Contacta al administrador.», solo cuando la contraseña es correcta, para no filtrar qué correos existen); `test_login_devuelve_debe_cambiar_password`.
- [ ] **Step 2: Ejecutar** `--filter=AuthPermisosTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** el servicio y ampliar las dos respuestas; el controlador delega en el servicio.
- [ ] **Step 4: Suite completa.** Expected: verde (los asserts de `AuthTest` existentes siguen válidos porque solo se agregan campos).

### Task 4: API de gestión de permisos (solo superAdmin)

**Files:**
- Create: `app/Http/Controllers/Api/V1/PermisoController.php`, `app/Http/Requests/Acceso/SincronizarPermisosRequest.php`
- Modify: `app/Services/Acceso/PermisosService.php`, `routes/api.php`
- Test: `tests/Feature/PermisosApiTest.php`

**Interfaces:**
- Consumes: `PermisosService`.
- Produces: `GET permisos` → `{roles: [{id, clave, nombre, es_super, modulos: [clave]}], modulos: [{clave, nombre, padre, solo_super, base}]}`; `PUT permisos/{rol}` (body `modulos: [clave,...]`, solo los **opcionales** a otorgar) → sincroniza `mnt_rol_modulo` conservando siempre los módulos `base` del rol; `PermisosService::matriz(): array`, `sincronizar(Rol $rol, array $claves): void`. Ambas rutas bajo `modulo:permisos`.
- Reglas (D8/R22): el rol `es_super` no se edita (422); las claves `solo_super` no se pueden asignar a un rol no super (422); las claves `base` en el body se ignoran (ya están) y **no se pueden quitar** aunque falten en el body; claves inexistentes → 422. Hoy el único opcional es `benchmark`.

- [ ] **Step 1: Tests que fallan:** `test_super_admin_ve_la_matriz`; `test_operario_recibe_403_en_permisos`; `test_se_puede_dar_benchmark_al_operario_y_la_api_lo_respeta_de_inmediato` (PUT, luego el operario recibe 200 en benchmark); `test_no_se_puede_asignar_usuarios_ni_permisos_al_operario`; `test_no_se_edita_el_rol_super_admin`; `test_clave_inexistente_da_422`; `test_quitar_benchmark_al_operario_da_403_en_la_siguiente_peticion`; `test_un_put_vacio_no_quita_los_modulos_base_al_operario` (sigue con 200 en gastos, aves, dashboard y chat).
- [ ] **Step 2: Ejecutar** `--filter=PermisosApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** controlador, request y los dos métodos del servicio (en transacción).
- [ ] **Step 4: Suite completa.** Expected: verde.

### Task 5: Gestión de usuarios y correo de credenciales

**Files:**
- Create: `app/Http/Controllers/Api/V1/UsuarioController.php`, `app/Http/Requests/Acceso/StoreUsuarioRequest.php`, `UpdateUsuarioRequest.php`, `app/Services/Acceso/UsuarioAdminService.php`, `app/Mail/CredencialesIniciales.php`, `resources/views/emails/credenciales-iniciales.blade.php`, `app/Jobs/EnviarCredencialesIniciales.php`
- Modify: `routes/api.php`, `docker-compose.yml` (servicio `queue`), `README.md` (variables `MAIL_*`, worker y flujo)
- Test: `tests/Feature/UsuariosApiTest.php`

**Interfaces:**
- Consumes: `Rol`, `User`, `PermisosService`.
- Produces: `GET usuarios` (lista con `rol`, `activo`), `POST usuarios` (`name`, `email` único, `id_rol` existente) → `{usuario, credenciales_estado: 'pendiente'}` (201; el correo sale por la cola), `PATCH usuarios/{id}` (`id_rol` y/o `activo`), `POST usuarios/{id}/reenviar-credenciales`. Todas bajo `modulo:usuarios`. `UsuarioAdminService::crear(array $datos): User` (crea con clave aleatoria desconocida, `debe_cambiar_password = true`, `credenciales_estado = 'pendiente'`, y despacha el job), `cambiarRol(User $objetivo, Rol $rol, User $actor): User`, `cambiarEstado(User $objetivo, bool $activo, User $actor): User`, `reenviarCredenciales(User $objetivo): User` (vuelve a `pendiente`, invalida la clave vigente con otra aleatoria desconocida y despacha el job). `EnviarCredencialesIniciales(int $idUsuario)` (`ShouldQueue`, `$tries = 3`, `backoff` 30 s, `timeout` 30 s, `WithoutOverlapping` por usuario): genera la temporal, guarda solo el hash, envía `CredencialesIniciales(User $usuario, string $passwordTemporal, string $urlSistema)` (Mailable en español) y marca `enviado`; `failed(Throwable)` marca `fallido` con un `credenciales_error` corto sin contraseña. URL del sistema: `config('app.frontend_url')` leída de `FRONTEND_URL` (agregar a `config/app.php`; por defecto `http://localhost:5173`).
- Reglas (Ruling 1, R21): el payload del job lleva **solo el `id_usuario`**; la temporal `Str::password(12, symbols: false)` se genera dentro del job y se guarda solo hasheada; si `config('mail.default')` es `log` o `array` el job **no envía**, deja `credenciales_estado = 'fallido'` con «El servidor de correo no está configurado.» y no escribe la contraseña en ningún log; si el transporte falla, tras los reintentos queda `fallido` y el usuario sigue creado; la contraseña en claro nunca va en la respuesta, en el log, en `jobs.payload` ni en `failed_jobs`; cambiar rol o desactivar revoca los tokens del objetivo (R16); desactivar y degradar al **único superAdmin activo** → 422; nadie se desactiva ni cambia su propio rol → 422. `compose`: servicio `queue` con la misma imagen y volúmenes que `api`, comando `php artisan queue:work --tries=3 --backoff=30 --max-time=3600`, `depends_on: postgres` saludable y `restart: unless-stopped`, sin publicar puertos.

- [ ] **Step 1: Tests que fallan** (con `config(['mail.default' => 'smtp'])` + `Mail::fake()` donde se envía; `Queue::fake()` donde solo se comprueba el despacho; el job se ejecuta a mano con `(new EnviarCredencialesIniciales($id))->handle()`): `test_crear_usuario_lo_deja_pendiente_y_encola_el_job_con_solo_el_id` (el payload serializado no contiene ninguna contraseña); `test_el_job_guarda_el_hash_envia_un_solo_correo_y_marca_enviado` (la `Mailable` capturada trae una contraseña que valida con `Hash::check` contra la guardada); `test_la_respuesta_no_contiene_la_contrasena_temporal`; `test_con_mailer_log_el_job_no_envia_y_marca_fallido` (`Mail::assertNothingSent`; la temporal no aparece en el contenido del log; el usuario sigue creado); `test_si_el_transporte_falla_queda_fallido_sin_contrasena_en_el_error` (llamando a `failed()`); `test_reenviar_credenciales_vuelve_a_pendiente_y_la_anterior_deja_de_servir`; `test_correo_duplicado_da_422`; `test_operario_no_puede_crear_usuarios`; `test_desactivar_revoca_tokens_y_bloquea_el_login`; `test_cambiar_rol_revoca_tokens`; `test_no_se_puede_desactivar_ni_degradar_al_unico_super_admin`; `test_nadie_se_desactiva_ni_se_cambia_el_rol_a_si_mismo`.
- [ ] **Step 2: Ejecutar** `--filter=UsuariosApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** servicio, job, mailable, vista del correo, requests, controlador y el servicio `queue` en `docker-compose.yml`; documentar en el README las variables `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `FRONTEND_URL`, y el worker (`docker compose up -d queue`, `docker compose restart queue` tras tocar un job, `docker compose logs -f queue`).
- [ ] **Step 4: Suite completa.** Expected: verde.
- [ ] **Step 5: Verificar el worker en Docker (sin tocar datos de negocio):** `docker compose up -d queue`, `docker compose ps` (Expected: `queue` en `running`), crear un usuario de prueba desde la API con `MAIL_MAILER=smtp` apuntando a `mailpit` o, con el mailer `log`, comprobar que el job termina y `credenciales_estado` queda `fallido` con el aviso; revisar con `SELECT` que `jobs.payload` no contiene la contraseña y que `failed_jobs` está vacía.

### Task 6: Cambio de contraseña y aviso de primer ingreso

**Files:**
- Create: `app/Http/Controllers/Api/V1/PasswordController.php`, `app/Http/Requests/Auth/CambiarPasswordRequest.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/PasswordApiTest.php`

**Interfaces:**
- Produces: `POST auth/password` (`password_actual`, `password`, `password_confirmation`; mínimo 8 caracteres): cambia la contraseña, pone `debe_cambiar_password=false` y **revoca todos los tokens** (incluido el actual, R11: debe volver a iniciar sesión). `POST auth/password/mantener`: pone la bandera en falso sin cambiar ningún secreto (R19). Ambas con solo `auth:sanctum` (cualquier usuario autenticado las necesita, sin módulo).

- [ ] **Step 1: Tests que fallan:** `test_cambiar_password_valida_la_actual`; `test_cambiar_password_actualiza_hash_apaga_la_bandera_y_revoca_todos_los_tokens`; `test_la_temporal_anterior_deja_de_servir_tras_cambiar`; `test_mantener_apaga_la_bandera_sin_tocar_la_password`; `test_confirmacion_distinta_da_422`; `test_sin_autenticar_da_401`.
- [ ] **Step 2: Ejecutar** `--filter=PasswordApiTest`. Expected: FAIL.
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Suite completa + Pint** solo sobre los archivos tocados de las Tareas 1–6.

## Frontend

### Task 7: Estado de autenticación con permisos y servicios

**Files:**
- Modify: `src/stores/auth.js`, `src/services/api.js`
- Create: `src/services/usuarios.js`, `src/services/permisos.js`, `src/stores/navegacion.js`

**Interfaces:**
- Produces: en `useAuthStore`: `user` (incluye `rol`, `modulos`, `permisos`, `debe_cambiar_password`), getters `puede(clave): boolean`, `modulos` (árbol), `esSuperAdmin`, acciones `cambiarPassword({password_actual, password, password_confirmation})` (hace logout al terminar con éxito) y `mantenerPassword()`. `useNavegacionStore`: `abierto: boolean`, `alternar()`, `abrir()`, `cerrar()`; recuerda la elección en `localStorage` (`avicola_sidebar_abierto`) con `try/catch` y por defecto abierto en escritorio, cerrado bajo `lg`. Servicios con las funciones `listar/crear/actualizar/reenviarCredenciales` (usuarios) y `obtenerMatriz/guardarRol` (permisos).

- [ ] **Step 1: Implementar** el store y los servicios; `login` y `fetchCurrentUser` guardan el usuario completo en `localStorage` (`avicola_user`).
- [ ] **Step 2: Verificar:** `npx vite build` en `avicola-frontend`. Expected: `✓ built`.

### Task 8: Sidebar, layout y barra superior reducida

**Files:**
- Create: `src/components/common/AppSidebar.vue`, `src/components/common/AppLayout.vue`
- Modify: `src/components/common/AppHeader.vue`, `src/router/index.js`, las 6 vistas (`DashboardView`, `InstalacionesView`, `AvesView`, `ProduccionView`, `GastosView`, `BenchmarkView`), `src/App.vue`

**Interfaces:**
- Consumes: `useAuthStore().modulos`, `useNavegacionStore`.
- Produces: `AppLayout` (sidebar + columna con `AppHeader` + `RouterView`), usado como ruta padre de las rutas autenticadas; el subtítulo del header sale de `route.meta.subtitle` (se pierde la prop por vista). `AppHeader` conserva logo/título, botón hamburguesa (`Menu`/`X`), nombre del usuario y «Cerrar sesión», y **pierde los módulos**. `AppSidebar` renderiza `authStore.modulos`: ítems con los estilos actuales del header (activo `font-semibold text-bosque bg-bosque-50 border border-bosque/20`, iconos `w-4 h-4 text-salvia`); el grupo «Asistente IA» es expandible y se auto-expande si la ruta activa es hija; mapa `icono → componente lucide` para los nombres sembrados (`LayoutDashboard`, `Home`, `Layers`, `Egg`, `DollarSign`, `Bot`, `Activity`, `Users`, `ShieldCheck`). Bajo `lg` es un panel superpuesto con backdrop que se cierra al navegar. Fondo base: superficie `bg-white` con borde derecho `border-borde` y `GallinaIcon` como marca de agua en la esquina inferior a baja opacidad (propuesta R8; el ajuste fino es la Tarea 11).
- z-index: sidebar y backdrop **por debajo de `z-[9999]`** de `BaseModal` y sin tapar el chat flotante (`z-40`).

- [ ] **Step 1: Implementar** los componentes y mover las 6 vistas al layout: en cada vista quitar `<AppHeader .../>` y su `import`, y cambiar la raíz `min-h-screen flex flex-col bg-crema text-verdoso` por `flex-1 flex flex-col`; el subtítulo pasa a `meta.subtitle` en `router/index.js`.
- [ ] **Step 2: Verificar:** `npx vite build`. Expected: `✓ built`, sin errores.
- [ ] **Step 3: Verificar en el navegador** (superAdmin, escritorio y móvil): sidebar abierto y colapsado, la elección se recuerda al recargar, ítem activo correcto en las 6 rutas, grupo «Asistente IA» con Benchmark dentro, un modal de cualquier vista queda por encima del sidebar, el chat flotante sigue visible.

### Task 9: Guardia de rutas y manejo del 403 a mitad de sesión

**Files:**
- Modify: `src/router/index.js`, `src/services/api.js`, `src/App.vue`
- Create: rutas `/usuarios` y `/permisos` (vistas en la Tarea 10) con `meta.modulo`

**Interfaces:**
- Produces: cada ruta de módulo lleva `meta.modulo: '<clave>'`; `router.beforeEach` redirige al Dashboard (o al primer módulo permitido si no tiene Dashboard) cuando `authStore.puede(meta.modulo)` es falso, con aviso `notify.warning('Ya no tienes acceso a ese módulo.')`. Interceptor de respuesta: ante un 403 con el mensaje de permiso, llama a `fetchCurrentUser()`, y si la ruta actual ya no está permitida va al Dashboard con el mismo aviso (R17); nunca muestra el error crudo. El widget del chat se muestra solo con `authStore.puede('asistente')` (R20).

- [ ] **Step 1: Implementar** guardia, interceptor y condición del chat.
- [ ] **Step 2: Verificar:** `npx vite build`; en el navegador con un operario: `/benchmark`, `/usuarios` y `/permisos` escritos a mano lo devuelven al Dashboard con aviso; quitarle `gastos` desde otra sesión y recargar una acción de Gastos lo lleva al Dashboard sin pantalla rota.

### Task 10: Pantallas de usuarios y permisos, y aviso de primer ingreso

**Files:**
- Create: `src/views/UsuariosView.vue`, `src/views/PermisosView.vue`, `src/components/usuarios/UsuarioFormModal.vue`, `src/components/common/PrimerIngresoModal.vue`, `src/components/common/CambiarPasswordModal.vue`
- Modify: `src/components/common/AppLayout.vue` (monta `PrimerIngresoModal`)

**Interfaces:**
- Consumes: servicios de la Tarea 7, `PageHeader`, `DataTable`, `StatusBadge`, `RowActionButton`, `FormField`, `BaseModal`, `BaseConfirmModal`, `EmptyState`, `LoadingState`, `notify.*`.
- Produces: `UsuariosView` (listado con rol y estado; crear con `UsuarioFormModal` — nombre, correo, rol; tras crear avisa «Se enviará el correo con las credenciales»; la columna «Credenciales» muestra un `StatusBadge` (`pendiente`, `enviado`, `fallido` con el motivo en el `title`) y la lista se refresca sola mientras haya alguno `pendiente`; activar/desactivar con `BaseConfirmModal`; «Reenviar credenciales»; las acciones sobre uno mismo y sobre el único superAdmin quedan deshabilitadas con la razón en el `title`); `PermisosView` (matriz rol × módulo: el rol super en solo lectura, los módulos `base` marcados y bloqueados, los `solo_super` no ofrecidos al operario, y un interruptor activo solo para los módulos opcionales —hoy Benchmark—; guardado por rol con aviso de éxito). `PrimerIngresoModal` se abre cuando `debe_cambiar_password` es verdadero, ofrece «Cambiar contraseña» (abre `CambiarPasswordModal`; al terminar se cierra la sesión y vuelve al login) y «Mantener esta contraseña» (`mantenerPassword()`); se puede cerrar con Escape o fuera sin elegir y reaparece en el siguiente inicio de sesión (R18).

- [ ] **Step 1: Implementar** las vistas, modales y rutas; todos los campos con `FormField`.
- [ ] **Step 2: Verificar:** `npx vite build`; en el navegador: crear un operario con un correo de prueba (con el mailer `log` el badge pasa a `fallido`; con `mailpit`/SMTP y el worker corriendo pasa a `enviado` y verás el correo), iniciar sesión con él, ver el aviso de primer ingreso, elegir «Mantener» y comprobar que no reaparece, repetir con «Cambiar» y comprobar que cierra sesión; dar y quitar `benchmark` al operario desde Permisos, y comprobar que los módulos base aparecen bloqueados.

### Task 11: Ajuste visual del sidebar con el usuario y documentación

**Files:**
- Modify: `src/components/common/AppSidebar.vue` (solo estilos, según lo que el usuario pida), `avicola-frontend/README.md`, `avicola-backend-api/README.md` (secciones de roles/permisos/usuarios), `docs/superpowers/plans/` ledger

- [ ] **Step 1: Mostrar el sidebar real al usuario** (escritorio y móvil, abierto/colapsado) y ajustar fondo, gallina de fondo, anchos y animación según lo que pida, respetando `DESIGN.md`.
- [ ] **Step 2: Documentar** en ambos README: roles, módulos base y opcionales, matriz inicial, endpoints nuevos, variables de correo, el worker de la cola (servicio `queue`, reinicio tras cambios), el comportamiento del aviso de primer ingreso y cómo asignar permisos. Crear el ledger `docs/superpowers/plans/2026-11-12-sidebar-roles-permisos-ledger.md` con los Rulings y su estado.
- [ ] **Step 3: Verificación final:** suite backend completa aislada (Expected: todo verde), `npx vite build` (Expected: `✓ built`) y revisión de `git status` en ambos repos para listar lo tocado; **sin commit**.
