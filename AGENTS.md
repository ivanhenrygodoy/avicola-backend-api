# AGENTS.md — Avícola Backend API (`avicola-backend-api`)

> **Propósito:** Dar contexto inmediato y reglas obligatorias a cualquier agente (en sesión nueva o continua) para trabajar con consistencia, reutilización, mantenibilidad y cero regresiones.

---

## 1. Lectura Obligatoria al Iniciar

Antes de proponer o editar código en este repositorio:
1. **Leer `README.md` (memoria viva del proyecto):** arquitectura, tablas, seeders, convenciones, formato de respuesta JSON y estado de cada módulo.
2. **Leer las skills disponibles en `.agents/skills/`:**
   - `.agents/skills/avicola-backend/SKILL.md` *(obligatoria siempre)*.
   - `.agents/skills/aviacore-asistente/SKILL.md` + los documentos referenciados en `docs/aviacore-arquitectura-harness.md`, `docs/aviacore-benchmark-guardrails.md` y `docs/aviacore-rag-embeddings.md` *(obligatoria cuando la tarea toque AviaCore, Tools, Guardrails, RAG, Conversaciones o Benchmark)*.
3. **Revisar herramientas y MCPs disponibles** (entorno Docker `avicola_api`, `avicola_postgres`, `avicola_queue`, skills y subagentes activos).

---

## 2. Principios de Trabajo (SDD, Memoria y Calidad)

- **Spec-Driven Development (SDD):** Antes de crear un módulo nuevo o hacer cambios estructurales, analizar, diseñar y documentar el plan en `docs/plans/YYYY-MM-DD-<tema>.md` para aprobación; al finalizar, actualizar su estado y resultados verificados.
- **`README.md` es nuestra memoria:** Toda nueva funcionalidad, tabla, migración, seeder, endpoint, variable de entorno o cambio de contrato **debe actualizar `README.md`** en la misma tarea.
- **Mantenibilidad, escalabilidad y reutilización:**
  - **Reutilizar antes de crear:** revisar controladores, servicios, `FormRequest` y catálogos existentes antes de introducir lógica nueva. Si dos bloques se parecen, extraer un servicio o helper compartido.
  - Si se requiere crear un componente o servicio nuevo, diseñarlo desacoplado, con responsabilidad única (SOLID) y respetando `SKILL.md`.
- **Navegación dirigida por módulo:** No leas todo el código fuente si trabajas en un módulo puntual; usa el mapa de abajo para ir directo a los archivos implicados.

---

## 3. Piezas Reutilizables del Backend

| Pieza | Ubicación / Uso | Cuándo reutilizar |
|---|---|---|
| Middleware de permisos | `EnsureModuloPermitido` (`middleware('modulo:<clave>')`) | Para proteger rutas de cualquier módulo nuevo o existente |
| Validación | `app/Http/Requests/<Modulo>/` | Toda petición con payload o filtros complejos |
| Lógica de dominio | `app/Services/` | Reglas de negocio, traslados, transacciones `DB::transaction()` |
| Zona horaria de negocio | `config('avicola.timezone')` (`AVICOLA_TIMEZONE`) | Agrupación de fechas, reportes, dashboard y benchmark |
| Catálogos compartidos | Rutas `catalogos/*`, `aves/catalogos`, `alimentos/tipos`, `gastos/categorias`, `instalaciones/tipos`, `casitas/tipos` | Selectores transversales sin duplicar endpoints |
| Envelope JSON | `{ success, message, data, meta, stats }` | Todas las respuestas bajo `/api/v1` |

---

## 4. Mapa de Módulos (Lectura Directa sin Ruido)

| Módulo | Controladores (`app/Http/Controllers/Api/V1/`) | Servicios / Modelos clave | Tests (`tests/Feature/`) |
|---|---|---|---|
| **Auth y Primer Ingreso** | `AuthController`, `PasswordController` | `PermisosService`, `User` | `AuthTest`, `AuthPermisosTest` |
| **Roles, Permisos y Usuarios** | `PermisoController`, `UsuarioController` | `PermisosService`, `Rol`, `Modulo`, `EnviarCredencialesIniciales` | `RolesYModulosTest`, `UsuariosTest` |
| **Dashboard** | `DashboardController` | `app/Services/Dashboard/` | `DashboardTest` |
| **Instalaciones** | `GranjaController`, `CasitaController`, `DivisionController` | `Granja`, `Casita`, `Division` | `InstalacionesTest` |
| **Inventario de Aves** | `AveController`, `PesoAveController`, `ObservacionAveController` | `app/Services/Aves/TrasladarAveService`, `Ave`, `MovimientoAve` | `AvesTest` |
| **Producción y Alimento** | `HuevoController`, `AlimentacionController` | `RegistroHuevo`, `RegistroAlimentacion` | `ProduccionTest` |
| **Finanzas y Gastos** | `GastoController` | `Gasto`, `CategoriaGasto` | `GastosTest` |
| **Asistente IA, RAG y Benchmark** | `AsistenteController`, `AsistenteConversacionController`, `AsistenteBenchmarkController`, `DepuracionLogsAgenteController` | `app/Services/Asistente/` *(leer skill `aviacore-asistente`)* | `AsistenteTest`, `Guardrails*Test`, `BenchmarkApiTest`, `ConversacionesAsistenteTest`, `RagZootecnicoTest` |

---

## 5. Entorno, Base de Datos y Verificación Obligatoria

- **Contenedores Docker:** `avicola_api` (puerto `8005`), `avicola_postgres`, `avicola_queue`.
- **Política de Base de Datos (Testing vs BD real `avicola_postgres`):**
  1. **Pruebas automatizadas (`php artisan test`):** Corren siempre en **SQLite `:memory:`** (`phpunit.xml` con `RefreshDatabase`). Nunca apuntar `RefreshDatabase` contra `avicola_postgres`.
  2. **Inspección de BD real (Solo Lectura por defecto):** Para investigar bugs con datos reales, el agente puede ejecutar consultas de lectura (`SELECT` vía `psql` en `avicola_postgres` o consultas de lectura en `tinker`). **Prohibido** ejecutar `INSERT`, `UPDATE`, `DELETE`, `TRUNCATE`, `migrate:fresh` o alterar registros reales por iniciativa propia, salvo solicitud explícita del usuario.
  3. **Mutaciones controladas:** Las migraciones nuevas (`php artisan migrate --force`) y seeders idempotentes (`db:seed --class=...`) se ejecutan únicamente cuando forman parte del plan aprobado; al tocar SQL propio de PostgreSQL (`ESCAPE '!'`, `pgvector`, FKs `restrictOnDelete()`), validar también sobre `avicola_postgres`.
- **Comandos clave:**
  - Migraciones y seeders (bajo plan aprobado): `docker exec avicola_api php artisan migrate --force` / `php artisan db:seed --force`
  - Reiniciar cola tras cambios en jobs/correos/env: `docker compose restart queue`
  - **Pruebas obligatorias:** `docker exec avicola_api php artisan test` (o el archivo específico del módulo durante desarrollo y la suite relevante al cerrar).

---

## 6. Git y Commits

- **No commitear ni hacer `push` por iniciativa propia:** el agente solo crea commits o ramas cuando el usuario lo solicita explícitamente.
- **Conventional Commits obligatorio:** cuando el usuario pida commitear, usar siempre el estándar Conventional Commits (`feat(scope): ...`, `fix(scope): ...`, `docs(scope): ...`, `test(scope): ...`, `refactor(scope): ...`, `chore(scope): ...`).
