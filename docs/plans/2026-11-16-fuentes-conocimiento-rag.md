# Plan SDD: Fuentes de Conocimiento RAG — Backend API (`avicola-backend-api`)

**Fecha:** 2026-11-16  
**Módulo:** `fuentes_conocimiento` (Submódulo de Asistente IA — Exclusivo Super Administrador)  
**Estado:** Finalizado con éxito

---

## 1. Objetivo

Permitir la gestión dinámica de fuentes documentales zootécnicas (archivos PDF, TXT, Markdown y texto directo) para alimentar el sistema RAG vectorial (`pgvector` 768d), con flujo de estados controlado (`borrador`, `publicada`, `archivada`), edición de borradores con validación estricta y exclusión automática de borradores/archivados en las consultas del asistente AviaCore.

---

## 2. Arquitectura y Base de Datos

1. **Catálogo `ctl_estado_fuente_conocimiento`**:
   - `borrador`: fuente registrada pendiente de edición o procesamiento vectorial.
   - `publicada`: fuente fragmentada, vectorizada y activa para búsqueda en `VectorStoreService`.
   - `archivada`: fuente retirada del RAG sin eliminar registros ni fragmentos (trazabilidad intacta).
2. **Extensión de `ctl_documentos_tecnicos`**:
   - Columnas: `id_estado`, `tipo_fuente` (`documento` | `texto`), `nombre_archivo_original`, `ruta_archivo`, `tamano_bytes`, `tipo_mime`, `total_palabras`, `fecha_procesado`.
3. **Seguridad y Permisos**:
   - Módulo `fuentes_conocimiento` bajo grupo padre `asistente` con `solo_super: true` en `RolesYModulosSeeder`.
   - Todas las rutas protegidas con `auth:sanctum` + `modulo:fuentes_conocimiento`.

---

## 3. Endpoints REST (`/api/v1/asistente/fuentes`)

| Método | Ruta | Acción | Descripción |
|---|---|---|---|
| `GET` | `/` | `index` | Listado paginado con filtros (`busqueda`, `especie`, `estado`) y KPIs (`stats`). |
| `POST` | `/` | `store` | Registra nueva fuente en `borrador` o procesada (`publicada`). Valida campos no vacíos. |
| `GET` | `/{id}` | `show` | Detalle de la fuente, fragmentos indexados y texto original. |
| `PUT` | `/{id}` | `update` | Edita los campos existentes de una fuente en `borrador` (y opcionalmente la procesa). Valida que no haya campos vacíos. |
| `POST` | `/{id}/procesar` | `procesar` | Ejecuta extracción, chunking y embeddings de un borrador y lo pasa a `publicada`. |
| `PATCH` | `/{id}/alternar-estado` | `alternarEstado` | Alterna entre `publicada` y `archivada`. |
| `GET` | `/{id}/descargar` | `descargar` | Descarga segura del archivo físico original o transcripción. |

---

## 4. Reglas de Validación y Negocio

- **Campos obligatorios al registrar, editar borrador y procesar**:
  - `tipo_fuente` (`documento` | `texto`)
  - `titulo`, `especie`, `autor`, `version`, `descripcion` (ninguno vacío)
  - `archivo` (PDF/TXT/MD máx. 20 MB cuando es `documento` nuevo o sin archivo previo) o `texto_contenido` (mín. 10 caracteres cuando es `texto`).
- **Inmutabilidad parcial**: Solo fuentes en estado `borrador` pueden editarse mediante `PUT /{id}`.
- **Aislamiento RAG**: `VectorStoreService` filtra estrictamente por `ctl_estado_fuente_conocimiento.clave = 'publicada'`.
- **Sin eliminación física**: No existe endpoint `DELETE`; la desactivación se realiza únicamente mediante archivado (`archivada`).

---

## 5. Verificación

- Pruebas Feature en `tests/Feature/FuenteConocimientoTest.php` (SQLite `:memory:`):
  - Control de acceso 403 para roles no Super Admin.
  - Listado y KPIs.
  - Registro en borrador y validación de campos vacíos (422).
  - Edición de borrador existente (metadata + contenido) y rechazo si no es borrador.
  - Procesamiento de borrador a publicada.
  - Alternancia `publicada` ⇄ `archivada` y exclusión en `VectorStoreService`.
  - Descarga segura de archivo.

