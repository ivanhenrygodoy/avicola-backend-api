# Plan SDD: Búsqueda Híbrida en RAG (Vectorial + Full-Text con RRF) — Backend (`avicola-backend-api`)

**Fecha:** 2026-11-25  
**Referencia de diseño:** `docs/ai-assistant-architecture-design.md` (Sección 8.4)  
**Estado:** Finalizado con éxito  
**Impacto en Frontend (`avicola-frontend`):** Sin cambios obligatorios en UI ni contratos API (`POST /asistente/chat` y `GET /asistente/benchmark/*` conservan su estructura).

---

## 1. Lineamientos de Diseño y Reglas de los Repositorios

Este plan respeta estrictamente las convenciones establecidas en `avicola-backend-api/README.md`, `avicola-frontend/README.md` y `.agents/skills/avicola-backend/SKILL.md`:

1. **Principios SOLID (SRP, OCP, DIP):**
   - Mantener `VectorStoreService::buscarSimilares()` intacto para compatibilidad hacia atrás.
   - Extraer la lógica matemática de fusión **Reciprocal Rank Fusion (RRF)** a una clase dedicada con responsabilidad única (`RrfFusionService`), inyectable por el contenedor de Laravel.
   - `ConsultarManualesTecnicosRAGTool` sigue implementando `AsistenteToolInterface` sin cambiar su nombre (`consultar_manuales_tecnicos`) ni su esquema público hacia el LLM.
2. **Compatibilidad Dual (PostgreSQL vs. SQLite `:memory:`):**
   - **PostgreSQL 16 (Producción / Docker):** Búsqueda vectorial nativa con `pgvector` (`<=>` + índice HNSW) combinada con búsqueda por texto completo nativa (`tsvector` generado + índice `GIN` + `websearch_to_tsquery` + `ts_rank_cd` en español). No requiere extensiones externas adicionales sobre la imagen `pgvector/pgvector:pg16`.
   - **SQLite `:memory:` (Testing CI/local):** Sin columna `tsvector` ni índice `GIN`. El fallback en PHP ejecuta la distancia coseno existente y una puntuación léxica determinista por tokens sobre `titulo_seccion` y `contenido`, aplicando exactamente el mismo `RrfFusionService`.
3. **Reglas de Base de Datos y Seguridad:**
   - Respetar filtros obligatorios de publicación (`ctl_documentos_tecnicos.activo = true` y `ctl_estado_fuente_conocimiento.clave = 'publicada'`) y filtro opcional por `especie`.
   - No exponer nombres de tablas (`mnt_`, `ctl_`) ni detalles internos en las respuestas del chat (`SystemLeakageGuard`).
4. **Aislamiento de Pruebas y Calidad:**
   - **Prohibido** correr `php artisan test` a secas dentro del contenedor `api` (borra la base `pgsql` de desarrollo). Usar siempre SQLite en memoria mediante variables de entorno explícitas.
   - Ejecutar `vendor/bin/pint` **únicamente sobre los archivos modificados**.
   - Seguir **Conventional Commits** en español y sin tildes (`feat(rag): ...`, `test(rag): ...`, `docs(rag): ...`).

---

## 2. Fases de Implementación (TDD)

### Tarea 1: Migración para índice de texto completo (`tsvector` + `GIN`)
- **Archivo:** `database/migrations/2026_11_25_100000_add_tsvector_to_mnt_documentos_fragmentos_table.php`
- **Detalle:**
  - En `up()`: si `DB::getDriverName() === 'pgsql'`:
    - Agregar columna generada automáticamente:
      ```sql
      ALTER TABLE mnt_documentos_fragmentos
      ADD COLUMN tsv tsvector GENERATED ALWAYS AS (
          setweight(to_tsvector('spanish', coalesce(titulo_seccion, '')), 'A') ||
          setweight(to_tsvector('spanish', coalesce(contenido, '')), 'B')
      ) STORED;
      ```
    - Crear índice `CREATE INDEX IF NOT EXISTS idx_fragmentos_tsv_gin ON mnt_documentos_fragmentos USING gin (tsv);`.
  - En `down()`: si `pgsql`, eliminar índice `idx_fragmentos_tsv_gin` y columna `tsv`.
  - En `sqlite`: no realizar cambios de esquema.
- **Ventaja:** Al ser `GENERATED ALWAYS ... STORED`, `IngestaManualesService` y `VectorStoreService::guardarFragmento()` no necesitan modificaciones.

### Tarea 2: Parámetros configurables en `config/asistente.php`
- **Archivo:** `config/asistente.php`
- **Detalle:** Agregar bloque `'rag'` con valores por defecto seguros y variables de entorno opcionales:
  - `rrf_k`: `60` (`ASISTENTE_RAG_RRF_K`)
  - `candidatos_por_rama`: `15` (`ASISTENTE_RAG_CANDIDATOS`)
  - `peso_vectorial`: `1.0` (`ASISTENTE_RAG_PESO_VECTORIAL`)
  - `peso_lexico`: `1.0` (`ASISTENTE_RAG_PESO_LEXICO`)

### Tarea 3: Servicio de fusión RRF (`RrfFusionService`)
- **Archivo:** `app/Services/Asistente/Rag/RrfFusionService.php`
- **Contrato:**
  - Método `fusionar(array $resultadosVectoriales, array $resultadosLexicos, int $limite = 3, int $k = 60, float $pesoVectorial = 1.0, float $pesoLexico = 1.0): array`
- **Lógica:**
  - Indexa cada fragmento por `id_fragmento`.
  - Suma la contribución de cada lista según su posición (base 1):
    $$\text{score}_{\text{RRF}}(d) = \frac{w_{\text{vec}}}{k + \text{rank}_{\text{vec}}(d)} + \frac{w_{\text{lex}}}{k + \text{rank}_{\text{lex}}(d)}$$
  - Añade campos informativos (`score_rrf`, `rank_vectorial`, `rank_lexico`) sin eliminar los campos actuales (`id`, `id_fragmento`, `documento_titulo`, `titulo_seccion`, `contenido`, `similitud`, etc.).
  - Ordena de forma descendente por `score_rrf` y retorna los primeros `$limite` elementos.

### Tarea 4: Búsqueda híbrida en `VectorStoreService`
- **Archivo:** `app/Services/Asistente/Rag/VectorStoreService.php`
- **Detalle:**
  - Inyectar `RrfFusionService` en el constructor.
  - Agregar método público:
    ```php
    public function buscarHibrido(
        string $consultaTexto,
        ?array $vectorQuery,
        int $limite = 3,
        ?string $especie = null
    ): array
    ```
  - **En `pgsql`:**
    1. Rama vectorial (solo si `$vectorQuery !== null`): consulta actual con `<=>` limitada a `candidatos_por_rama`.
    2. Rama léxica (si `trim($consultaTexto) !== ''`): consulta con `websearch_to_tsquery('spanish', ?)` filtrando `f.tsv @@ query` y ordenando por `ts_rank_cd(f.tsv, query) DESC`, limitada a `candidatos_por_rama`.
    3. Ambas ramas aplican `d.activo = true`, `est.clave = 'publicada'` y el filtro opcional de `d.especie`.
    4. Fusionar ambas listas con `RrfFusionService::fusionar(...)`.
  - **En `sqlite` (fallback de pruebas):**
    1. Rama vectorial (si `$vectorQuery !== null`): reutiliza `calcularDistanciaCoseno()` y toma top `candidatos_por_rama`.
    2. Rama léxica en PHP: normaliza texto (minúsculas, sin acentos, tokenización por palabras `>= 2` caracteres), calcula puntaje por coincidencias en `titulo_seccion` (peso `2.0`) y `contenido` (peso `1.0`) sobre los fragmentos filtrados, descarta puntajes `0` y toma top `candidatos_por_rama`.
    3. Fusionar ambas listas con `RrfFusionService::fusionar(...)`.

### Tarea 5: Integración en `ConsultarManualesTecnicosRAGTool` y trazabilidad en Benchmark
- **Archivo:** `app/Services/Asistente/Tools/ConsultarManualesTecnicosRAGTool.php`
- **Detalle:**
  - En `ejecutar()`, intentar generar el embedding con `GeminiApiClient::generarEmbedding($consulta)`.
  - Si `generarEmbedding()` lanza excepción, asignar `$vector = null` (en lugar de contaminar el ranking con el vector MD5) para que la búsqueda híbrida se apoye limpiamente en la rama léxica.
  - Invocar `$this->vectorStore->buscarHibrido($consulta, $vector, limite: 3, especie: $especie)`.
  - Determinar la estrategia efectiva de recuperación según los resultados obtenidos (`hibrida`, `vectorial` o `lexica`) y agregar:
    - `estrategia_busqueda`: `'hibrida' | 'vectorial' | 'lexica'`
    - `mensaje`: resumen legible, p. ej. `"Se recuperaron 3 fragmentos mediante búsqueda híbrida (vectorial + léxica)."` (o `"búsqueda vectorial"` / `"búsqueda léxica"` según aplique).
  - **Impacto automático en Benchmark y Logs:** `AsistenteOrquestadorService` toma `$resultado['mensaje']` y lo guarda en `mnt_funcion_log_agent_tools.resumen_resultado` sin modificar migraciones, servicios de auditoría ni el frontend (`LogDetalleModal.vue`).
  - Conservar el resto de la estructura de retorno hacia el LLM (`total_fragmentos`, `encontrado`, `fragmentos`, `orientacion_pedagogica`).

### Tarea 6: Suite de pruebas (TDD en `tests/Feature/RagTest.php`)
- **Archivo:** `tests/Feature/RagTest.php`
- **Casos nuevos a cubrir:**
  1. **Fusión RRF:** Un fragmento que aparece en ambos rankings (vectorial y léxico) supera a fragmentos que solo destacan en uno.
  2. **Rescate léxico de términos exactos:** Una consulta con un código o término técnico específico (ej. `"NDV-LaSota"` o `"Dendrocygna"`) recupera el fragmento exacto aunque el vector favorezca otro documento.
  3. **Respaldo semántico sin coincidencia literal:** Si la rama léxica devuelve 0 coincidencias, la rama vectorial sigue devolviendo los fragmentos relevantes.
  4. **Resiliencia ante fallo de embeddings:** Si `GeminiApiClient::generarEmbedding()` falla, `ConsultarManualesTecnicosRAGTool` sigue recuperando fragmentos válidos mediante la rama léxica.
  5. **Respeto de filtros:** Documentos inactivos (`activo = false`), en estado `borrador`/`archivada`, o de otra `especie` siguen excluidos en ambas ramas.

### Tarea 7: Verificación y Documentación
- **Comandos de verificación (Backend):**
  ```bash
  docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test --filter=RagTest
  docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test
  docker compose exec -T api ./vendor/bin/pint --test <archivos_modificados>
  ```
- **Validación en PostgreSQL real (Docker):**
  - Ejecutar `docker compose exec api php artisan migrate --force` y probar una consulta real al endpoint `/api/v1/asistente/chat` verificando que el índice `idx_fragmentos_tsv_gin` y `idx_fragmentos_embedding_hnsw` operan sin errores SQL.
- **Validación de Frontend (`avicola-frontend`):**
  - No se modifican archivos de frontend; verificar opcionalmente con `npm run build` y prueba visual en `AsistenteChatWidget.vue` (badge `Manuales técnicos zootécnicos`) y `BenchmarkView.vue` (`LogDetalleModal.vue`).
- **Documentación:**
  - Actualizar `avicola-backend-api/README.md` (Sección 5.8) y `avicola-backend-api/docs/aviacore-rag-embeddings.md` reflejando la búsqueda híbrida + RRF.
