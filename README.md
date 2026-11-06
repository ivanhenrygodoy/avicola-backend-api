# Avícola · Backend API

API REST de la **Granja Avícola Inteligente**: administra instalaciones, inventario de aves y sus movimientos, producción de huevos, alimentación, gastos y un dashboard analítico. Está construida con **Laravel 13 (PHP 8.3)**, **PostgreSQL 16** y autenticación con **Laravel Sanctum**, y se ejecuta en Docker.

> Este documento describe el estado actual del código para guiar futuros desarrollos. La hoja de ruta por etapas del proyecto completo vive en el `README.md` de la carpeta raíz (`Proyects-IA/`); aquí se documenta únicamente el backend.

## Contenido

1. [Puesta en marcha](#1-puesta-en-marcha)
2. [Arquitectura y estructura](#2-arquitectura-y-estructura)
3. [Convenciones de base de datos](#3-convenciones-de-base-de-datos)
4. [Formato estándar de respuestas](#4-formato-estándar-de-respuestas)
5. [Módulos](#5-módulos)
6. [Principios SOLID aplicados](#6-principios-solid-aplicados)
7. [Seguridad](#7-seguridad)
8. [Pruebas](#8-pruebas)
9. [Estándares de desarrollo y commits](#9-estándares-de-desarrollo-y-commits)
10. [Skills y documentos de referencia](#10-skills-y-documentos-de-referencia)
11. [Cómo agregar un módulo nuevo](#11-cómo-agregar-un-módulo-nuevo)

---

## 1. Puesta en marcha

### Con Docker (recomendado)

`docker-compose.yml` levanta dos servicios en la red `avicola_network`:

| Servicio | Imagen | Puerto | Notas |
|---|---|---|---|
| `api` | `php:8.3-cli-alpine` (ver `Dockerfile`) | `8005` | Espera a que Postgres esté sano antes de iniciar |
| `postgres` | `pgvector/pgvector:pg16` | `${DB_PORT:-5432}` | Volumen persistente `postgres_data` y *healthcheck* con `pg_isready` |

```bash
docker compose up -d
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force
```

`docker-entrypoint.sh` crea los directorios de `storage`, instala `vendor/` si falta, copia `.env.example` a `.env` si no existe y espera a PostgreSQL.

### Variables de entorno

Copia `.env.example` a `.env`. Las claves relevantes son `APP_URL`, `DB_*` y `AVICOLA_TIMEZONE` (opcional, por defecto `America/El_Salvador`, ver [zona horaria](#zona-horaria-de-negocio)).

### Datos de desarrollo

| Seeder | Qué crea |
|---|---|
| `DatabaseSeeder` | Usuario administrador, catálogos, instalaciones, 14 aves, producción y gastos iniciales |
| `DashboardDemoSeeder` | 30 días de huevos y alimentación, un pico de rotura, gastos y observaciones para ver el dashboard con datos |

`DashboardDemoSeeder` **no** forma parte de `DatabaseSeeder`: se ejecuta a demanda y es idempotente.

```bash
docker compose exec api php artisan db:seed --class=DashboardDemoSeeder --force
```

Usuario de desarrollo: `admin@granja.local` / `password123` (solo para entornos locales).

---

## 2. Arquitectura y estructura

```
app/
├── Http/
│   ├── Controllers/Api/V1/   # Controladores delgados (reciben, delegan, responden)
│   └── Requests/<Modulo>/    # FormRequests: validación y autorización por acción
├── Models/                   # Entidades Eloquent: relaciones, casts, scopes
├── Providers/                # AppServiceProvider: registro de servicios e indicadores
└── Services/
    ├── Aves/                 # TrasladarAveService
    └── Dashboard/            # Resumen analítico y actividad (ver módulo 6)
config/avicola.php            # Configuración propia (zona horaria de negocio)
database/{migrations,seeders} # Esquema y datos
routes/api.php                # Todas las rutas bajo /api/v1
tests/Feature/                # Pruebas por módulo
```

Flujo de una petición: **ruta → FormRequest (valida) → controlador (delgado) → servicio o modelo → respuesta JSON estándar.**

---

## 3. Convenciones de base de datos

| Tipo | Prefijo | Ejemplos |
|---|---|---|
| Catálogos | `ctl_` | `ctl_especie`, `ctl_sexo`, `ctl_proposito`, `ctl_tipo_instalacion`, `ctl_tipos_alimento`, `ctl_categorias_gastos` |
| Negocio / operación | `mnt_` | `mnt_usuarios`, `mnt_granjas`, `mnt_casitas`, `mnt_divisiones`, `mnt_aves`, `mnt_movimientos_aves`, `mnt_registros_peso`, `mnt_observaciones_aves`, `mnt_registros_huevos`, `mnt_registros_alimentacion`, `mnt_gastos` |

- **Clave primaria:** `id`.
- **Claves foráneas:** siempre `id_<entidad>` (`id_granja`, `id_casita`, `id_division`, `id_ave`, `id_especie`, `id_categoria_gasto`…). Para relaciones con rol, `id_division_origen` / `id_division_destino`.
- **Integridad:** `restrictOnDelete()` en relaciones críticas (no se borra una casita con divisiones, ni una división con aves).
- **Borrado lógico del inventario:** un ave no se elimina; pasa a estado `salida` y conserva su historial.
- Cada migración crea las tablas con sus índices (por ejemplo `['fecha', 'id_division']` en producción y alimentación).

---

## 4. Formato estándar de respuestas

Todas las respuestas bajo `/api/v1` son JSON con la misma forma:

```json
// Éxito (recurso o acción)
{ "success": true, "message": "Casita creada exitosamente.", "data": { } }

// Colección paginada (con estadísticas cuando aplica)
{ "success": true, "data": [ ], "meta": { "current_page": 1, "last_page": 3, "per_page": 10, "total": 24, "from": 1, "to": 10 }, "stats": { } }

// Error de validación o de negocio (HTTP 422)
{ "success": false, "message": "No se puede eliminar la casita porque tiene divisiones asociadas." }
```

- Las rutas `api/*` siempre responden JSON (`shouldRenderJsonWhen` en `bootstrap/app.php`).
- Las fechas de los modelos de producción, alimentación y gastos se serializan `d-m-Y`; las series del dashboard usan `Y-m-d` porque alimentan ejes de gráfico.

---

## 5. Módulos

Todas las rutas requieren `auth:sanctum`, salvo `POST /auth/login`.

### 5.1 Autenticación (`AuthController`)

| Método | Ruta | Descripción |
|---|---|---|
| POST | `/auth/login` | Valida credenciales y emite un token Bearer |
| POST | `/auth/logout` | Elimina el token actual |
| GET | `/auth/me` | Datos del usuario autenticado |

`LoginRequest` limita a **5 intentos por minuto** por clave de acceso (`RateLimiter`); un intento fallido suma al contador y uno correcto lo limpia. Las contraseñas se almacenan con hash.

### 5.2 Instalaciones (`CasitaController`, `DivisionController`)

Modela la granja física: **Granja → Casitas → Divisiones (corrales)**.

- CRUD de casitas y divisiones, con `toggle-activa` para habilitar o deshabilitar.
- Catálogo de tipos de instalación (`/instalaciones/tipos`, `/casitas/tipos`, `/catalogos/tipos-instalacion`; las tres rutas devuelven lo mismo).
- **Reglas de negocio:** no se elimina una casita que tenga divisiones ni una división que tenga aves (HTTP 422 con mensaje explicativo). El listado devuelve `stats` (`total_casitas`, `total_divisiones` y `divisiones_activas`) y admite los filtros `buscar`, `tipo`, `id_tipo_instalacion` y `activa`.

### 5.3 Inventario de aves (`AveController`, `TrasladarAveService`)

- CRUD de aves con catálogos de especie, sexo y propósito (`/aves/catalogos`, `/aves/especies`, `/aves/sexos`, `/aves/propositos` y los alias bajo `/catalogos/*`).
- Estados aceptados: `activa`, `trasladada`, `fallecida` y `salida`. En la práctica los flujos actuales solo asignan `activa` (alta, traslado y reingreso) y `salida` (baja); `trasladada` y `fallecida` se aceptan al validar pero ningún flujo los asigna todavía. `DELETE /aves/{ave}` **no borra**: registra un movimiento de baja, deja el ave en `salida` y sin división.
- Filtros del listado: `search`, `id_especie`, `id_sexo`, `estado` (`todos` desactiva el filtro), `id_division` e `id_casita`. `stats` devuelve `total_aves`, `activas_count` y conteos por gallinas, chompipes y patos.
- `PATCH /aves/{ave}/reactivar` registra el reingreso y valida compatibilidad biológica si se asigna corral.
- `POST /aves/{ave}/trasladar` delega en `TrasladarAveService`, que dentro de una **transacción** registra el movimiento y actualiza la ubicación. Antes valida:
  1. la división destino está activa;
  2. no es la misma división actual;
  3. **regla biológica:** en una división solo conviven aves activas de la misma especie (`ctl_especie`).
- `GET /aves/{ave}/movimientos` devuelve el historial de traslados, bajas y reingresos.
- Seguimiento individual: pesos (`/aves/{ave}/pesos`, `/pesos/{peso}`), donde un observer de `RegistroPeso` mantiene `peso_actual_kg` del ave, y bitácora sanitaria (`/aves/{ave}/observaciones`, `/observaciones/{observacion}`) con tipos `Salud`, `Comportamiento`, `Vacunación`, `Incidencia`, `Nutrición` y `General`.

### 5.4 Producción y alimentación (`HuevoController`, `AlimentacionController`)

- **Huevos** (`/huevos`): recolección diaria por división con filtros de búsqueda, división y rango de fechas. Reglas: `cantidad_rotos <= cantidad_recolectada` y cantidades no negativas. `cantidad_aprovechables` se calcula en el modelo al guardar (`recolectados − rotos`).
- **Alimentación** (`/alimentacion`): raciones por división y tipo de alimento (catálogo en `/catalogos/tipos-alimento`), con cantidad, unidad (`kg` por defecto) y costo opcional.
- Ambos listados devuelven `stats` calculados sobre el filtro aplicado (por ejemplo `total_kilos`, `total_costo`, `porcentaje_rotura`).

### 5.5 Gastos (`GastoController`)

CRUD de gastos operativos con categoría (`/catalogos/categorias-gastos`), división opcional, concepto y proveedor. Filtros por búsqueda, categoría, división y rango de fechas. `stats` incluye el total del filtro y `total_mes_actual`.

### 5.6 Dashboard analítico (`DashboardController`)

Dos endpoints, ambos con agregados en SQL (sin cargar colecciones de modelos):

| Método | Ruta | Parámetros | Devuelve |
|---|---|---|---|
| GET | `/dashboard/resumen` | `dias` ∈ {7, 30} (por defecto 7) | `aves`, `ocupacion`, `huevos`, `alimentacion`, `gastos`, `alertas` |
| GET | `/dashboard/actividad` | `page`, `per_page` ∈ {5, 10, 20} | Lista paginada y `meta` |

**Diseño (SRP + OCP + DIP).** Cada indicador es una clase que implementa `IndicadorDashboard` (`clave()` y `calcular(PeriodoDashboard)`) en `app/Services/Dashboard/Indicadores/`. `AppServiceProvider` los registra con un *tag* y `DashboardResumenService` los recibe inyectados: **agregar un indicador es crear una clase y añadirla a `INDICADORES_DASHBOARD`**, sin tocar el servicio ni el controlador.

| Indicador | Qué calcula |
|---|---|
| `AvesResumen` | Aves activas por especie y por sexo |
| `OcupacionResumen` | Aves activas por casita y división (conteo; las divisiones no tienen capacidad definida), incluye divisiones vacías o inactivas |
| `ProduccionHuevosResumen` | Hoy, rango elegido, últimos 7 y 30 días y serie diaria con los días sin registro en 0, en una sola consulta agrupada |
| `AlimentacionResumen` | Kilos de hoy y del mes (solo registros con unidad `kg`) |
| `GastosResumen` | Total del mes y desglose por categoría |
| `AlertasResumen` | `sin_postura_hoy`, `sin_alimentacion_hoy` (solo si hay aves activas) y `rotura_alta` (más de 10 % en 7 días) |

`ActividadRecienteService` mezcla traslados, recolecciones, gastos y observaciones, los ordena por momento de registro y expone como máximo los **50 eventos** más recientes, paginados en el servidor.

#### Zona horaria de negocio

La aplicación guarda `timestamps` en UTC, pero "hoy" y "este mes" se calculan con `config('avicola.timezone')` (`America/El_Salvador`) mediante el objeto de valor `PeriodoDashboard`. Un registro hecho a las 23:30 hora local cuenta en su día aunque ya sea el siguiente en UTC.

---

## 6. Principios SOLID aplicados

| Principio | Cómo se aplica aquí |
|---|---|
| **S** Responsabilidad única | Controladores delgados; `FormRequest` solo valida; los servicios encapsulan flujos y transacciones; los modelos solo describen entidades |
| **O** Abierto/cerrado | Los indicadores del dashboard se extienden con una clase nueva y una línea de registro |
| **L** Sustitución de Liskov | Cualquier `IndicadorDashboard` es intercambiable para `DashboardResumenService` |
| **I** Segregación de interfaces | Un `FormRequest` por acción (`StoreCasitaRequest`, `UpdateCasitaRequest`…) y un contrato mínimo de dos métodos para los indicadores |
| **D** Inversión de dependencias | Los servicios se inyectan por el contenedor de Laravel; `DashboardResumenService` depende del contrato, no de clases concretas |

---

## 7. Seguridad

- Tokens Bearer con **Sanctum**; `logout` revoca el token en base de datos.
- **Rate limiting** de 5 intentos por minuto en el login.
- **CORS restrictivo** (`config/cors.php`): solo `http://localhost:5173` y `http://127.0.0.1:5173`. Cualquier otro origen del frontend debe añadirse ahí.
- Validación de toda entrada con `FormRequest`; eager loading (`with(...)`) para evitar consultas N+1; transacciones en operaciones que modifican varias tablas.
- `.env`, la base SQLite local y los logs están fuera de git.

---

## 8. Pruebas

Las pruebas están en `tests/Feature/` y usan `RefreshDatabase`: `AuthTest`, `InstalacionesTest`, `AvesTest`, `ProduccionTest`, `SeguimientoGastosTest` y `DashboardTest`.

> ### ⚠️ Advertencia importante: aislamiento de la base de datos
>
> `phpunit.xml` configura SQLite en memoria, pero **el contenedor `api` define `DB_CONNECTION=pgsql` y `DB_*` en su entorno**, y esas variables tienen prioridad sobre las de `<env>`. Un test de comprobación mostró que la suite se conecta a la base de desarrollo (`avicola_inteligente_db`). Como todas las pruebas usan `RefreshDatabase` (`migrate:fresh`), **ejecutar `php artisan test` dentro del contenedor vacía la base de datos de desarrollo**.
>
> Este problema está **sin resolver** (añadir `force="true"` a las variables de `phpunit.xml` no lo corrigió). Antes de correr la suite, aísla las pruebas con una base de datos de test separada o un contenedor desechable. Si vacías por error la base de desarrollo, vuelve a sembrarla con `migrate` y `db:seed` (y `DashboardDemoSeeder` si quieres el historial de demostración).

Las pruebas del dashboard cubren, entre otras cosas: autenticación y validación de parámetros, base vacía, conteo solo de aves activas, relleno de días sin registro, zona horaria de negocio (un registro a las 23:30 local), exclusión de unidades distintas de `kg`, umbral exacto de rotura y paginación del feed.

---

## 9. Estándares de desarrollo y commits

- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/) en español y sin tildes, con alcance: `feat(dashboard): …`, `fix(aves): …`, `refactor(…): …`, `style(…): …`. El cuerpo explica el porqué y lista los cambios relevantes.
- **Formato de código:** Laravel Pint (`vendor/bin/pint`). Ejecútalo **solo sobre los archivos que modificaste**, nunca sobre todo el proyecto.
- **Pruebas primero** (TDD) en los módulos nuevos, y una prueba por cada regla de negocio.
- **Respuestas, validaciones y mensajes** en español, con el formato estándar de la sección 4.
- **Nombres:** tablas con prefijo `ctl_`/`mnt_`, claves foráneas `id_<entidad>`, FormRequests `Store…Request` / `Update…Request`.
- **Antes de hacer `push`** revisa `git status` para no incluir `.env` ni archivos temporales.

---

## 10. Skills y documentos de referencia

Este proyecto cuenta con *skills* (guías para asistentes de IA) y documentos que describen las reglas anteriores. Léelos antes de desarrollar:

| Recurso | Ubicación | Contenido |
|---|---|---|
| Skill `avicola-backend` | `.agents/skills/avicola-backend/SKILL.md` (también en `Proyects-IA/.agents/skills/`) | Convenciones de BD, SOLID, formato JSON, seguridad, pruebas y Docker |
| Skill `avicola-frontend` | `Proyects-IA/.agents/skills/avicola-frontend/` | Reglas del cliente que consume esta API |
| Skill `avicola-design-system` | `Proyects-IA/.agents/skills/avicola-design-system/` | Sistema de diseño compartido |
| Skill `impeccable` | `Proyects-IA/.agents/skills/impeccable/` | Guía de calidad de diseño de interfaz |
| `docs/DESIGN_SYSTEM_BACKEND.md` | este repo | Resumen de estándares de arquitectura |
| `AGENTS.md` / `CLAUDE.md` | este repo | Instrucciones para asistentes (Laravel Boost) |

---

## 11. Cómo agregar un módulo nuevo

1. **Migración** con prefijo `mnt_` o `ctl_`, claves `id_<entidad>` y `restrictOnDelete()` donde corresponda.
2. **Modelo** con `$table`, `$fillable`, `casts()` y relaciones.
3. **FormRequests** `Store…` y `Update…` en `app/Http/Requests/<Modulo>/`.
4. **Controlador delgado** en `app/Http/Controllers/Api/V1/`; si hay un flujo con varias tablas o reglas cruzadas, un **servicio** con `DB::transaction`.
5. **Rutas** dentro del grupo `auth:sanctum` de `routes/api.php`.
6. **Respuestas** con el formato estándar y `stats` si el listado muestra indicadores.
7. **Pruebas** en `tests/Feature/` (consulta primero la [advertencia de la sección 8](#8-pruebas)).
8. **Seeder** de datos de ejemplo si el módulo lo necesita.
9. Si es un indicador del dashboard: clase en `Services/Dashboard/Indicadores/` que implemente `IndicadorDashboard` y alta en `INDICADORES_DASHBOARD`.
