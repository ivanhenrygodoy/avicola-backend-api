---
name: aviacore-asistente
description: Arquitectura, reglas y referencias obligatorias para trabajar en el asistente virtual AviaCore, sus tools, guardrails, conversaciones persistentes, RAG con pgvector y el módulo Benchmark AviaCore.
---

# Skill: Asistente IA AviaCore (Harness, Guardrails, RAG y Benchmark)

> **Regla de entrada:** Antes de modificar cualquier archivo del asistente, **lee el documento específico** de `docs/` correspondiente a la capa que vas a tocar:

1. **Harness, Function Calling, Orquestador y Tools:**
   - Leer `docs/aviacore-arquitectura-harness.md`
2. **Guardrails (entrada, tool, bucle, salida) y Auditoría (Benchmark):**
   - Leer `docs/aviacore-benchmark-guardrails.md`
3. **Base de Conocimiento RAG, Embeddings y `pgvector`:**
   - Leer `docs/aviacore-rag-embeddings.md`

---

## 1. Flujo Estructural del Asistente (`POST /api/v1/asistente/chat`)

`AsistenteController` → `AsistenteOrquestadorService` → `GuardrailPipeline`:
1. **SaludoDetectorService:** Responde saludos sin consumir tokens ni sugerir módulos.
2. **Guardrails de entrada:** Bloquean peticiones fuera de dominio antes de llamar a Gemini.
3. **PromptComposer (4 capas):** Construye contexto con historial (`en_contexto`) de `mnt_mensajes_asistente`. **El cliente nunca envía el historial**; el servidor lo arma desde la conversación validada contra el usuario autenticado (anti-IDOR).
4. **AsistenteToolRegistry + Loop Guard:** Ejecuta tools declaradas en formato Gemini; los **guardrails de herramienta** validan fechas, especies y argumentos antes de consultar PostgreSQL.
5. **RAG (`pgvector`):** `ConsultarManualZootecnicoTool` recupera fragmentos de manuales zootécnicos oficiales; no inventar respuestas fuera del contexto recuperado.
6. **Guardrails de salida + Benchmark:** Sanean nombres internos (`mnt_`, `ctl_`, rutas) y registran la auditoría en `mnt_funcion_log_agent` y `mnt_funcion_log_agent_tools` **sin datos del usuario**.

---

## 2. Reglas Críticas que Nunca Deben Romperse

- **Aislamiento de conversaciones:** El UUID lo genera el servidor (`mnt_conversaciones_asistente`). Todo acceso valida propiedad contra `auth()->id()`. Un UUID ajeno o eliminado responde `404`. "Eliminar" cambia estado lógico (`estado = 'eliminada'`), nunca borra filas.
- **Nombrado automático (`TituloConversacionService`):** Se ejecuta en petición aparte tras el primer turno exitoso y **no se audita** en Benchmark. Nunca pisa nombres manuales.
- **Resiliencia:** Fallos en la capa de conversación se registran en `laravel.log` pero **no deben romper** la respuesta del chat.
- **Búsquedas SQL en PostgreSQL (`BenchmarkConsultaService`):** Escapar `%` y `_` usando `ESCAPE '!'` (nunca `ESCAPE '\'`, que falla en PDO-pgsql).
- **Sin métricas de costo:** No calcular ni persistir costos monetarios en Benchmark.
- **Pruebas:** Al modificar tools, guardrails, RAG, conversaciones o benchmark, ejecutar `docker exec avicola_api php artisan test` (cubriendo `AsistenteTest`, `GuardrailsEntradaTest`, `GuardrailsToolTest`, `GuardrailsSalidaTest`, `BenchmarkApiTest`, `ConversacionesAsistenteTest`, `RagZootecnicoTest`).
