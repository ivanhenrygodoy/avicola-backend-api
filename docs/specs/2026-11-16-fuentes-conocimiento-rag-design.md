# Diseño: Módulo de Fuentes de Conocimiento RAG y Gestión Documental Vectorial

Fecha: 2026-11-16 · Repos: `avicola-backend-api` (Laravel 11, PostgreSQL 16 + pgvector) y `avicola-frontend` (Vue 3, Pinia, Tailwind CSS)

---

## 1. Intención y Contexto

Actualmente, el asistente virtual **AviaCore** utiliza búsqueda vectorial semántica mediante la herramienta `ConsultarManualesTecnicosRAGTool` apoyada en la tabla `mnt_documentos_fragmentos` (con `pgvector` y 768 dimensiones). Sin embargo, los manuales zootécnicos existentes fueron cargados de forma fija mediante un seeder estático (`IngestaManualesService`).

Para dotar al sistema de escalabilidad real, se incorpora el **Módulo de Gestión de Fuentes de Conocimiento RAG**, permitiendo al Super Administrador:
1. Subir archivos zootécnicos (.pdf, .txt, .md) o redactar/pegar texto directamente.
2. Controlar el ciclo de vida de los documentos mediante estados normados (`borrador`, `publicada`, `archivada`).
3. Ejecutar la fragmentación (*chunking*) y generación de embeddings con Gemini (`text-embedding-004`).
4. Auditar visualmente cada documento con un modal de detalle que desglosa los fragmentos vectorizados y el texto original.
5. Descargar el archivo fuente original y archivar documentos obsoletos sin destruirlos.

---

## 2. Objetivos, No-Objetivos y Anti-Objetivos

### Objetivos
- **Catálogo de estados formal**: Crear `ctl_estado_fuente_conocimiento` (`borrador`, `publicada`, `archivada`).
- **Trazabilidad documental en BD**: Actualizar `ctl_documentos_tecnicos` con clave foránea `id_estado`, tipo de fuente (`documento` o `texto`), nombre original, ruta en almacenamiento, tamaño y tipo MIME.
- **Servicio de Extracción y Chunking**: Extraer texto de archivos `.txt`, `.md` y `.pdf`, y trocear en fragmentos semánticos con metadatos.
- **Generación asíncrona/síncrona de Embeddings**: Procesar fragmentos y guardarlos en `mnt_documentos_fragmentos` con vector de 768 dimensiones.
- **Rutas API Seguras**: Endpoints RESTful bajo `/api/v1/asistente/fuentes` protegidos por middleware `modulo:fuentes_conocimiento` y acceso solo para Super Admin.
- **Descarga segura**: Endpoint para descargar el archivo físico subido.
- **Búsqueda RAG condicionada**: Actualizar `VectorStoreService` y `ConsultarManualesTecnicosRAGTool` para que **únicamente** consulten fragmentos de fuentes en estado `publicada`.
- **Frontend accesible y fiel a capturas**:
  - Pantalla principal con KPIs, filtros y tabla de documentos.
  - Modal dinámico de registro (conmutación entre selector de archivo y área de texto directo).
  - Modal de detalle con tarjetas métricas, visor de fragmentos indexados, visor de texto original y botón condicional de «Procesar» para borradores.
  - Acciones de archivar / desarchivar.

### No-Objetivos (esta fase)
- Edición de fragmentos individuales a mano dentro de un documento ya vectorizado (se actualiza el documento completo o se sustituye).
- OCR para PDFs basados exclusivamente en imágenes escaneadas sin capa de texto.

### Anti-Objetivos
- Ejecutar llamadas a la API de Gemini cuando el usuario solo guarda en `borrador`.
- Permitir que fragmentos de documentos en `borrador` o `archivada` contaminen las respuestas zootécnicas del asistente.
- Dejar archivos huérfanos en disco al eliminar una fuente.
- Permitir que roles sin privilegios (`operario`) visualicen o alteren la base documental.

---

## 3. Decisiones Tomadas con el Usuario

- **D1. Entrada dinámica en registro**: El selector *"Tipo de fuente"* conmuta dinámicamente entre el selector de archivos (`documento`: PDF, TXT, MD) y un área de texto amplia (`texto`: escritura o pegado manual).
- **D2. Descarga de archivos**: El documento físico se guarda en `storage/app/documentos_rag/` y se puede descargar desde la tabla y el detalle.
- **D3. Permisos estrictos**: Exclusivo para Super Administrador (`solo_super: true`).
- **D4. Máquina de Estados**:
  - `borrador`: Creada pero no procesada (0 fragmentos generados).
  - `publicada`: Vectorizada con embeddings y activa para el RAG de AviaCore.
  - `archivada`: Desactivada para el asistente, preservando histórico y fragmentos sin borrarlos.
- **D5. Acciones contextuales, Switch Publicada/Archivada y sin opción de eliminar**:
  - **No existirá opción de eliminar**: la baja o retiro de una fuente del RAG se gestiona exclusivamente archivándola, garantizando trazabilidad histórica y preservando los datos.
  - En cada fila de `DataTable`:
    - **Ver Detalle (`Eye`)**: Abre `DetalleFuenteModal` con auditoría de fragmentos y texto original.
    - **Switch Toggle Publicada / Archivada**:
      - Para fuentes ya procesadas (`publicada` o `archivada`), un switch visual directo en la fila permite conmutar entre ambos estados.
      - Al hacer clic en el switch, se abre `ConfirmStatusModal` solicitando confirmación antes de mutar el estado (explicando si pasará a estar activa o inactiva en las consultas del asistente).
    - **Acción para Borradores**: Si la fuente está en `borrador`, muestra un botón/badge de acción **Publicar / Procesar** con confirmación en `ConfirmStatusModal` para vectorizarla.
    - **Descargar (`Download`)**: Permite descargar el archivo original.
- **D6. Flujo en Modales**:
  - Modal de Registro permite: *Guardar en borrador* o *Registrar y procesar*.
  - En el modal de detalle, si la fuente está en *borrador*, se muestra el botón **«Procesar e Indexar»**; si ya está *publicada*, se muestra **«Cerrar»**.
- **D7. Cumplimiento estricto de AGENTS.md**:
  - Frontend: `PageHeader`, `StatCard`, `FilterBar` (solo con filtros, sin `FormField`), `DataTable`, `PaginationBar`, `StatusBadge`, `RowActionButton`, `FormField` (exclusivo para el modal de registro), `BaseModal`, `BaseConfirmModal` (botones siempre centrados), `ActionLoader`. Prohibido `alert()` o `confirm()` nativo.
  - Backend: Convenciones `ctl_`, `mnt_`, `id_<entidad>`, envelope `{success, message, data}`, FormRequests dedicados, middleware `modulo:fuentes_conocimiento` y tests en SQLite `:memory:`.

---

## 4. Arquitectura de Base de Datos

### 4.1. Nueva Tabla: `ctl_estado_fuente_conocimiento`
```sql
CREATE TABLE ctl_estado_fuente_conocimiento (
    id SERIAL PRIMARY KEY,
    clave VARCHAR(50) UNIQUE NOT NULL,      -- 'borrador', 'publicada', 'archivada'
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255) NULL,
    color_badge VARCHAR(50) DEFAULT 'gray', -- para StatusBadge frontend
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### 4.2. Migración aditiva en `ctl_documentos_tecnicos`
```sql
ALTER TABLE ctl_documentos_tecnicos
    ADD COLUMN id_estado INTEGER NOT NULL REFERENCES ctl_estado_fuente_conocimiento(id),
    ADD COLUMN tipo_fuente VARCHAR(20) DEFAULT 'documento', -- 'documento' | 'texto'
    ADD COLUMN nombre_archivo_original VARCHAR(255) NULL,
    ADD COLUMN ruta_archivo VARCHAR(255) NULL,
    ADD COLUMN tamano_bytes BIGINT NULL,
    ADD COLUMN tipo_mime VARCHAR(100) NULL,
    ADD COLUMN total_palabras INTEGER DEFAULT 0,
    ADD COLUMN fecha_procesado TIMESTAMP NULL;

CREATE INDEX idx_documentos_estado ON ctl_documentos_tecnicos(id_estado);
```

---

## 5. Arquitectura del Backend

### 5.1. Servicios
1. **`ExtraccionTextoService`**:
   - Lee archivos `.txt`, `.md` y `.pdf` (vía parser robusto de texto).
   - Sanitiza y normaliza caracteres Unicode y saltos de línea.
2. **`ChunkingService`**:
   - Divide el texto en secciones coherentes:
     - Si detecta encabezados estructurados (`#`, `##`, `Capítulo`, etc.), trocea respetando títulos.
     - Si es texto continuo, aplica ventana de 400 a 700 palabras con 50 palabras de solapamiento (*overlap*).
3. **`FuenteConocimientoService`**:
   - Orquesta la creación en borrador, subida de archivos, ejecución del pipeline de embeddings vía `GeminiApiClient` y almacenamiento en `VectorStoreService`.
   - Transiciones de estado: `procesar()` (borrador -> publicada), `archivar()` (publicada -> archivada), `desarchivar()` (archivada -> publicada).

### 5.2. Controladores y FormRequests
- `App\Http\Controllers\Api\V1\Asistente\FuenteConocimientoController`
- `App\Http\Requests\Asistente\StoreFuenteConocimientoRequest`
- `App\Http\Requests\Asistente\UpdateFuenteConocimientoRequest`

### 5.3. Endpoints REST
- `GET /api/v1/asistente/fuentes`: Listado paginado con filtros (búsqueda, especie, estado) y conteo de fragmentos.
- `POST /api/v1/asistente/fuentes`: Registro de fuente (soporta multipart con archivo o JSON con texto; flags `accion: 'borrador' | 'procesar'`).
- `GET /api/v1/asistente/fuentes/{id}`: Detalle completo con lista de fragmentos y texto original.
- `POST /api/v1/asistente/fuentes/{id}/procesar`: Procesa un borrador existente y genera embeddings.
- `PATCH /api/v1/asistente/fuentes/{id}/archivar`: Pasa a estado `archivada`.
- `PATCH /api/v1/asistente/fuentes/{id}/desarchivar`: Regresa a estado `publicada`.
- `GET /api/v1/asistente/fuentes/{id}/descargar`: Descarga el archivo original subido.
- `DELETE /api/v1/asistente/fuentes/{id}`: Elimina fuente, archivo en disco y fragmentos en cascada.

---

## 6. Arquitectura del Frontend

- **Vista**: `src/views/FuentesConocimientoView.vue` (ruta `/asistente/fuentes`).
- **Componentes**:
  - `src/components/asistente/fuentes/RegistrarFuenteModal.vue`: Modal de registro dinámico (con inputs `FormField`, selector de archivo y textarea alternante).
  - `src/components/asistente/fuentes/DetalleFuenteModal.vue`: Modal con métricas, tabs de fragmentos indexados / texto original, y botón de acción condicional «Procesar e Indexar».
- **Store & Service**:
  - `src/stores/fuentesConocimiento.js`
  - `src/services/fuentesConocimiento.js`
- **Sidebar**:
  - Registro del módulo `fuentes_conocimiento` como hijo de `asistente` con icono `BookOpen`.

---

## 7. Pruebas y Criterios de Aceptación

1. **Pruebas Backend (`FuenteConocimientoTest.php`)**:
   - Super Admin puede registrar borrador sin generar embeddings.
   - Procesar borrador trocea el texto y genera fragmentos con embeddings en `mnt_documentos_fragmentos`.
   - RAG vectorial ignora fragmentos de fuentes en borrador o archivadas.
   - Endpoint de descarga retorna el archivo con sus encabezados MIME correctos.
   - Operario recibe 403 al intentar acceder a cualquier endpoint del módulo.
2. **Pruebas Frontend / E2E**:
   - Alternancia reactiva del tipo de fuente (Archivo vs Texto directo).
   - Guardar borrador deja la tarjeta en estado «Borrador» y permite procesarla después desde el detalle.
   - Cambio de tema claro/nocturno verificado en los nuevos modales y tablas.

