# Etapa 8: Base de Conocimiento RAG con pgvector Implementation Plan

**Estado:** Finalizado con éxito

> **Enfoque de Desarrollo:** Spec-Driven Development (SDD) con TDD en Backend y Component Driven en Frontend.
> **Referencia:** `README.md` §Etapa 8, `avicola-frontend/DESIGN.md`, `.agents/skills/avicola-{backend,frontend,design-system}`, patrones de `documentacion-externa/embeddings.md`.

**Goal:** Implementar un sistema de Generación Aumentada por Recuperación (RAG) utilizando la extensión `pgvector` en PostgreSQL 16 y el modelo de embeddings de Google Gemini (`text-embedding-004`), permitiendo al Asistente Virtual responder consultas zootécnicas especializadas (crianza, sanidad, nutrición y bioseguridad de gallinas criollas, chompipes y patos pichiches) citando documentación técnica verificada.

**Architecture:**
- **Base de Datos (pgvector & Convenciones):**
  - Migración con `CREATE EXTENSION IF NOT EXISTS vector;` (PostgreSQL) y tabla relacional compatible con SQLite para pruebas automatizadas.
  - `ctl_documentos_tecnicos`: Catálogo de manuales técnicos y literatura de referencia (`titulo`, `especie`, `descripcion`, `autor`, `version`, `activo`).
  - `mnt_documentos_fragmentos`: Fragmentos técnicos (*chunks*) de conocimiento (`id_documento_tecnico`, `titulo_seccion`, `contenido`, `metadata`, `embedding vector(768)`).
  - Índice HNSW en PostgreSQL para cálculo acelerado de distancia coseno (`vector_cosine_ops` / `<=>`).
- **Servicios de Embeddings y Vector Store:**
  - `GeminiApiClient`: Método `generarEmbedding(string $texto): array` consumiendo `v1beta/models/text-embedding-004:embedContent`.
  - `VectorStoreService`: Servicio de persistencia y búsqueda vectorial por similitud coseno (`buscarSimilares(array $vector, int $limite = 3, ?string $especie = null)`), con fallback transparente en SQLite para tests.
  - `IngestaManualesService`: Procesa y fragmenta manuales técnicos de gallinas criollas, chompipes y patos pichiches, generando y almacenando sus vectores.
  - Comando Artisan `php artisan avicola:ingestar-manuales` y Seeder `DocumentosTecnicosSeeder`.
- **Integración con Asistente Virtual (OCP):**
  - `ConsultarManualesTecnicosRAGTool`: Nueva herramienta autorizada que implementa `AsistenteToolInterface`.
  - Se registra en `AppServiceProvider::$ASISTENTE_TOOLS` sin tocar el controlador ni el orquestador.
  - `PromptComposer`: Se actualiza la Capa de Dominio y Estilo para instruir al LLM a citar explícitamente el manual técnico de origen y distinguir literatura de datos operativos.
- **Frontend:**
  - El widget flotante (`AsistenteChatWidget.vue` y `AsistenteMensaje.vue`) ya soporta badges de herramientas y renderizado enriquecido; al invocar `consultar_manuales_tecnicos` se mostrará automáticamente el badge *"Manuales técnicos zootécnicos"*.

**Tech Stack:** Laravel 13 / PHP 8.3 / PostgreSQL 16 con `pgvector` (Docker `postgres` / `api`) / Gemini `text-embedding-004` / PHPUnit 12; Vue 3 + Tailwind CSS 3.

---

## Global Constraints

- **No hacer commits automáticos** hasta que el usuario lo indique expresamente (árbol de trabajo limpio y revisable por git diff).
- **Aislamiento estricto de pruebas en BD:**
  Ejecutar siempre los tests con:
  `docker compose -f avicola-backend-api/docker-compose.yml exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test ...`
  (Evita que `RefreshDatabase` vacíe la base de datos de desarrollo en Postgres).
- **Compatibilidad Dual (PostgreSQL / SQLite):**
  - La migración debe soportar `vector(768)` en PostgreSQL y `text` en SQLite.
  - `VectorStoreService` debe ejecutar búsqueda vectorial nativa (`<=>`) en PostgreSQL y búsqueda simulada/textual en SQLite para que la suite de pruebas corra al 100% sin dependencias nativas en el entorno de test.
- **Linter de código:** Laravel Pint (`vendor/bin/pint`) ejecutado **únicamente sobre los archivos modificados**.
- **Seguridad:** Cero SQL arbitrario; la búsqueda semántica se canaliza estrictamente a través de `VectorStoreService` y la tool autorizada.

---

## Review Focus

- Migración ejecuta limpiamente en PostgreSQL con `CREATE EXTENSION IF NOT EXISTS vector;` y en SQLite de pruebas.
- Generación de embeddings con `text-embedding-004` produce exactamente 768 dimensiones.
- Búsqueda por similitud coseno recupera los fragmentos más relevantes y filtra opcionalmente por especie.
- Pregunta técnica sobre patos pichiches (ej. "¿Qué cuidados y alimentación requieren los patos pichiches?"): Gemini invoca `consultar_manuales_tecnicos` y responde citando el *Manual del Pato Pichiche*.
- Pregunta que combina datos de la granja y literatura técnica (ej. "¿Qué comen los patos y cuánto alimento tenemos registrado?"): Gemini invoca `consultar_manuales_tecnicos` y `consultar_raciones_alimento`, respondiendo de forma integrada.
- Pregunta sin relación técnica: el asistente no inventa recomendaciones y responde con honestidad.

---

## File Structure

### Backend (`avicola-backend-api/`):
- Create `database/migrations/2026_11_09_180000_create_documentos_tecnicos_tables.php` — extensión pgvector y tablas `ctl_documentos_tecnicos` y `mnt_documentos_fragmentos`.
- Create `app/Models/CtlDocumentoTecnico.php` — modelo de documento maestro.
- Create `app/Models/MntDocumentoFragmento.php` — modelo de fragmento vectorial.
- Modify `app/Services/Asistente/GeminiApiClient.php` — método `generarEmbedding(string $texto): array`.
- Create `app/Services/Asistente/Rag/VectorStoreService.php` — búsqueda semántica y cálculo de similitud.
- Create `app/Services/Asistente/Rag/IngestaManualesService.php` — lógica de ingesta y fragmentación de manuales.
- Create `app/Console/Commands/IngestarManualesCommand.php` — comando Artisan `avicola:ingestar-manuales`.
- Create `database/seeders/DocumentosTecnicosSeeder.php` — datos técnicos de manuales (gallinas criollas, chompipes, patos pichiches).
- Create `app/Services/Asistente/Tools/ConsultarManualesTecnicosRAGTool.php` — tool RAG para el Asistente.
- Modify `app/Services/Asistente/PromptComposer.php` — instrucción de citación de manuales y distinción de literatura.
- Modify `app/Providers/AppServiceProvider.php` — registro de `ConsultarManualesTecnicosRAGTool` en `ASISTENTE_TOOLS`.
- Modify `tests/Feature/AsistenteTest.php` o Create `tests/Feature/RagTest.php` — suite de pruebas automatizadas.

### Frontend (`avicola-frontend/`):
- Modify `src/components/asistente/AsistenteMensaje.vue` — mapeo legible de la herramienta `consultar_manuales_tecnicos` ("Manuales técnicos zootécnicos").
- Modify `src/components/asistente/AsistenteChatWidget.vue` — sugerencia rápida adicional sobre literatura técnica (ej. "¿Qué cuidados requiere el pato pichiche?").

---

## Tareas de Implementación (SDD / TDD)

### Task 1: Migración Vectorial y Modelos Eloquent (`pgvector`)

**Files:**
- Create: `database/migrations/2026_11_09_180000_create_documentos_tecnicos_tables.php`
- Create: `app/Models/CtlDocumentoTecnico.php`
- Create: `app/Models/MntDocumentoFragmento.php`
- Test: `tests/Feature/RagTest.php`

**Interfaces:**
- Produces: Tablas `ctl_documentos_tecnicos` y `mnt_documentos_fragmentos`.
- Produces: Relación `CtlDocumentoTecnico::hasMany(MntDocumentoFragmento::class, 'id_documento_tecnico')`.
- Produces: Relación `MntDocumentoFragmento::belongsTo(CtlDocumentoTecnico::class, 'id_documento_tecnico')`.

**Steps:**
- [ ] **Step 1: Write failing test** en `RagTest.php` comprobando creación de manuales y fragmentos con relaciones y casts.
- [ ] **Step 2: Run test** en Docker con SQLite aislado → FAIL.
- [ ] **Step 3: Implement** migración dual (PostgreSQL `vector(768)` / SQLite `text`) y modelos Eloquent.
- [ ] **Step 4: Run test** → PASS.

---

### Task 2: Cliente de Embeddings y Servicio Vector Store

**Files:**
- Modify: `app/Services/Asistente/GeminiApiClient.php` (`generarEmbedding`)
- Create: `app/Services/Asistente/Rag/VectorStoreService.php`
- Test: `tests/Feature/RagTest.php`

**Interfaces:**
- Produces: `GeminiApiClient::generarEmbedding(string $texto): array` (retorna vector de 768 dimensiones).
- Produces: `VectorStoreService::guardarFragmento(int $idDocumento, string $seccion, string $contenido, array $embedding, array $metadata = []): MntDocumentoFragmento`.
- Produces: `VectorStoreService::buscarSimilares(array $vectorQuery, int $limite = 3, ?string $especie = null): array`.

**Steps:**
- [ ] **Step 1: Write failing test** con `Http::fake()` para `generarEmbedding()` y búsqueda de fragmentos con orden por similitud.
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** `GeminiApiClient::generarEmbedding` y `VectorStoreService`.
- [ ] **Step 4: Run test** → PASS.

---

### Task 3: Manuales Técnicos Zootécnicos e Ingesta

**Files:**
- Create: `app/Services/Asistente/Rag/IngestaManualesService.php`
- Create: `database/seeders/DocumentosTecnicosSeeder.php`
- Create: `app/Console/Commands/IngestarManualesCommand.php`
- Test: `tests/Feature/RagTest.php`

**Contenido Zootécnico de Manuales:**
1. *Manual Técnico de Crianza y Sanidad del Pato Pichiche (Dendrocygna autumnalis)*.
2. *Guía Zootécnica para la Producción y Manejo de Pavos / Chompipes*.
3. *Manual de Manejo Integral y Bioseguridad de la Gallina Criolla*.

**Steps:**
- [ ] **Step 1: Write failing test** comprobando que el seeder ingesta los 3 manuales con fragmentos técnicos organizados por temas (sanidad, nutrición, bioseguridad, instalaciones).
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** `IngestaManualesService`, seeder y comando Artisan.
- [ ] **Step 4: Run test** → PASS.

---

### Task 4: Herramienta RAG `ConsultarManualesTecnicosRAGTool` y Prompt Enriquecido

**Files:**
- Create: `app/Services/Asistente/Tools/ConsultarManualesTecnicosRAGTool.php`
- Modify: `app/Services/Asistente/PromptComposer.php` (actualizar Capa de Dominio con directrices de citación)
- Modify: `app/Providers/AppServiceProvider.php` (agregar a `ASISTENTE_TOOLS`)
- Test: `tests/Feature/RagTest.php` y `tests/Feature/AsistenteTest.php`

**Steps:**
- [ ] **Step 1: Write failing test** comprobando:
  - La herramienta `consultar_manuales_tecnicos` genera el embedding de la duda y recupera los fragmentos pertinentes.
  - El orquestador ejecuta la tool cuando el usuario hace una pregunta sobre cuidados del pato pichiche o prevención de coriza aviar.
  - Gemini cita el documento técnico en su respuesta.
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** la tool RAG, actualizar `PromptComposer` y registrar en `AppServiceProvider`.
- [ ] **Step 4: Run test** → PASS.

---

### Task 5: Frontend - Actualización de Badges y Sugerencias RAG

**Files:**
- Modify: `avicola-frontend/src/components/asistente/AsistenteMensaje.vue`
- Modify: `avicola-frontend/src/components/asistente/AsistenteChatWidget.vue`

**Steps:**
- [ ] **Step 1: Update** `nombresHerramientas` con `'consultar_manuales_tecnicos': 'Manuales técnicos zootécnicos'`.
- [ ] **Step 2: Add** sugerencia rápida de consulta técnica en el widget: *"¿Qué cuidados requiere el pato pichiche?"*.
- [ ] **Step 3: Verify** `npm run build` sin errores.

---

### Task 6: Ejecución de Migración Real en PostgreSQL, Ingesta y Verificación E2E

**Steps:**
- [ ] **Step 1: Run migration** en el contenedor Docker PostgreSQL: `docker compose exec api php artisan migrate`.
- [ ] **Step 2: Run seeder** en PostgreSQL: `docker compose exec api php artisan db:seed --class=DocumentosTecnicosSeeder`.
- [ ] **Step 3: Suite completa de pruebas:** Verificar que los 95+ tests pasen al 100% con SQLite en memoria.
- [ ] **Step 4: Verificación en vivo en navegador:** Realizar consultas técnicas al asistente comprobando citas bibliográficas y respuestas zootécnicas precisas.

