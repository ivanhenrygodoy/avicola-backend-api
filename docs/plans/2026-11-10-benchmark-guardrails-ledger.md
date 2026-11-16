# SDD ledger — plan: docs/superpowers/plans/2026-11-10-benchmark-guardrails-asistente.md

**Estado:** Finalizado con éxito

Pre-flight: Task 2 produces GuardrailInterface/ResultadoPipeline -> Tasks 3,4,5,7 consume (fase values entrada|tool|bucle|salida). Task 4 SystemLeakageGuard::sanear -> Task 6 consumes. Task 5 tablasConsultadas -> Task 7 consumes. Task 1 models -> Tasks 6,8 consume. Task 8 API -> Tasks 10,11 consume. Task 10 PillTabs/RangeToggle/store -> Task 11 consumes. Clean.
Ruling: no worktree/branch, work in place on main of both repos with NO commits — user rule (memory: no commitear sin orden; plan Global Constraints) overrides skill's worktree/commit steps; "task-done"/review-package scripts need git ranges so ledger is written by hand — cost if wrong: no per-task git ranges; use file list instead.
Baseline: backend 104 passed; frontend build OK. Dev DB counts before: aves=14 gastos=5 huevos=5 alimentacion=3.
Task 1: complete (files: 2 migrations, FuncionLogAgent, FuncionLogAgentTool, tests/Feature/BenchmarkLogTest; tests: --filter=BenchmarkLogTest -> 2/2 pass; dev DB migrated additively, counts unchanged)
Task 2: complete (files: Guardrails/{Contracts/GuardrailInterface,ContextoGuardrail,ResultadoGuardrail,ResultadoPipeline,GuardrailPipeline}, config/guardrails.php, AppServiceProvider tag asistente.guardrails; tests: --filter=GuardrailsPipelineTest -> 10/10 pass)
Task 2: Ruling: added GuardrailPipeline::total() and a config-messages test, plus config key `limite_tools` (plan listed mensajes without it; Task 5 needs it) — cost if wrong: trivial.
Task 2: Ruling: fast_path event is logged with accion='bloqueo' (spec accion enum has no fast_path; resultado column carries fast_path) — cost if wrong: log reads "bloqueo" for greetings.
Task 3: complete (files: Guardrails/GuardrailBase + Entrada/{SaludoFastPath,PromptInjection,ProfanityAndToxicity,PersonaDrift,ContextTampering,MathematicalOffloading,ScopeEnforcement}Guard, AppServiceProvider registers 7 guards; tests: GuardrailsEntradaTest 28/28 pass, with Pipeline+AsistenteTest still green)
Task 3: Ruling: added abstract GuardrailBase (nombre/normalizar/patrones/vocabulario helpers) so the 7 guards don't repeat text-normalization code (DRY) — cost if wrong: one extra file.
Task 3: Ruling: removed 'basura' from toxicity patterns (it is a legit farm word: "basura del corral") and added 'muestrame' to injection verbs — found by tests; cost if wrong: a few insults not caught.
Task 3: Ruling: test file uses #[DataProvider] attributes (PHPUnit 12 ignores @dataProvider annotations) — cost: none.
Task 4: complete (files: Guardrails/Salida/{SystemLeakage,DateFormat,SourceAttribution,LengthAndBrevity}Guard, AppServiceProvider registers them; tests: GuardrailsSalidaTest 17/17 pass; Guardrails|AsistenteTest|BenchmarkLogTest all green)
Task 4: Ruling: DateFormatGuard leaves impossible dates (2026-13-45) untouched via checkdate (likely a lot code, not a date) — cost if wrong: a malformed date stays raw.
Task 4: Ruling: SystemLeakageGuard blocks on any mnt_/ctl_ mention, route, trace, cURL/SQLSTATE or key= (per spec "jamás se filtren"); a legit sentence mentioning "app/…" would be replaced — cost if wrong: rare false block, visible in Benchmark as bloqueado_guard.
Task 5: complete (files: AsistenteToolInterface::tablasConsultadas + 6 tools, Guardrails/Tool/ToolArgumentValidationGuard, Guardrails/Bucle/ToolLoopGuard, AppServiceProvider registers both, AsistenteTest 3 anonymous tools gained tablasConsultadas(); tests: GuardrailsToolTest 10/10 + AsistenteTest green = 31/31)
Task 5: Ruling: tests for tool/loop guards live in new GuardrailsToolTest.php (plan said extend GuardrailsPipelineTest) — separate responsibility, cost: none. Real table names are singular for ctl_especie/ctl_sexo/ctl_proposito (plan said plural) — verified against models; test asserts each declared table exists in the schema.
Task 5: Ruling: ToolArgumentValidationGuard uses DateTimeImmutable::createFromFormat (Carbon throws on invalid input) — found by test.
Task 6: complete (files: Benchmark/RastroTurno, Benchmark/FuncionLogAgentService; tests: BenchmarkRegistradorTest 8/8 pass)
Task 6: Ruling: registrador tests in new BenchmarkRegistradorTest.php (plan said extend BenchmarkLogTest) — keeps schema vs. service tests separate; cost: none. Added static FuncionLogAgentService::versionPromptVigente() so Task 8 reuses the same hash — cost: none.
Task 7: complete (files: AsistenteOrquestadorService rewritten on GuardrailPipeline+RastroTurno, ChatRequest uuid_conversacion, AsistenteController passes uuid, FuncionLogAgentService::sinIdentidad made public static; tests: BenchmarkOrquestadorTest 13/13 + AsistenteTest 21/21 = 34/34; full suite run next)
Task 7: Ruling: orchestrator tests in new BenchmarkOrquestadorTest.php; existing AsistenteTest untouched in intent — cost: none. Removed SaludoDetectorService/MAX_ITERACIONES from orchestrator as the plan says (SaludoDetectorService class itself is kept and reused by SaludoFastPathGuard).
Task 7: Ruling: orquestador constructor keeps ($client,$registry,$resolver) and appends optional ?pipeline,?recorder resolved via app() — existing tests that build it with 2 args keep working. The tool-validation error is returned to the LLM as ['error'=>texto] and the turn continues.
Task 7: Ruling: turn that fails with a provider exception is recorded (resultado=error, error_mensaje sanitized) then rethrown so the controller keeps its 503 + full laravel.log entry — as in the plan.
Task 8: complete (files: Benchmark/BenchmarkConsultaService, AsistenteBenchmarkController, EstadisticasBenchmarkRequest, FiltroLogsAgenteRequest, routes asistente/benchmark/*; tests: BenchmarkApiTest 17/17; full suite 209/209)
Task 8: Ruling: LIKE escaping uses ESCAPE '!' instead of backslash — PDO-pgsql raised "Invalid parameter number" on ESCAPE '\' (SQLite hid it); verified read-only against the dev Postgres via tinker. Service aggregates the day series in PHP (portable to SQLite) over a bounded 7/30-day window.
Task 8: Ruling: controller reads validated() filters; `stats` is computed over the same filter (resumenDelFiltro) as the plan says.
Task 9: complete (files: database/seeders/BenchmarkDemoSeeder; tests: BenchmarkLogTest 4/4 incl. idempotent + marked-for-deletion + no other tables). Seeder NOT executed on the dev DB (awaiting user confirmation per plan).
Task 9: Ruling: plan's "Task 9 verification" (suite + Pint + docs) is deferred to the end (Task 14) so backend docs/Pint run once over the final file set; Task 9 here = the demo seeder (plan Step 2b). Cost: none.
Task 10: complete (files: services/benchmark.js, stores/benchmark.js, common/PillTabs.vue, common/RangeToggle.vue, utils/benchmark.js; edits: router/index.js route /benchmark, AppHeader.vue nav item; npm run build OK)
Task 11: complete (files: views/BenchmarkView.vue, benchmark/SerieDiariaCard, RankingCard, LogDetalleModal; npm run build OK; browser verification pending)
Task 10/11: Ruling: AppHeader nav label is short "Benchmark" (bar is already tight at lg); full title "Benchmark AviaCore" is the page title/subtitle — cost: none. Extra file utils/benchmark.js centralizes result/action/phase labels so table, modal and stats don't repeat them (DRY). Logs tab also shows 4 StatCards of the current filter (uses `stats` from the API, which the plan defined but the view list omitted).
Task 12 (migrate Produccion to PillTabs/RangeToggle): NOT executed — gated on user approval per plan; will ask at the end.
Task 13: complete (files: stores/asistente.js uuidConversacion + services/asistente.js doc; npm run build OK). Verified in browser: both turns from the widget share uuid fbd55cb6-… in mnt_funcion_log_agent.
Task 9 (Step 1-3 deferred part) + docs: Pint run only over files touched; docs/aviacore-arquitectura-harness.md section 10 added; suite 211/211; build OK.
Browser verification (Task 11/14, dev servers on :5173 + :8005): "suma 2+3" -> bloqueado_guard/MathematicalOffloadingGuard/0 tokens/0 iteraciones; "escribe un poema" -> ScopeEnforcementGuard; real Gemini 503 -> resultado=error, error_mensaje sanitized, chat showed controlled message. Stats tab (rankings, bars %) and Logs tab (table, badges, 4 StatCards, pagination) and detail modal (prompt version, respuesta, 0 tools, guard list) all rendered. Dev counts after: aves=14 gastos=5 huevos=5 alimentacion=3 (unchanged), log rows=3 (real, from my manual test).
Final: Ruling: BenchmarkView reloads the active tab's data on tab switch (watch(activeTab)) — found in browser: tab showed stale 0 rows after new interactions; cost: one extra request per switch.
Final: Ruling: used the user's existing Vite dev server on :5173 (CORS allows only 5173) instead of killing it; my temporary :5199 server was stopped.
Final review: independent reviewer dispatched (most capable model), awaiting.
Final: fixed #1 math guard false positives (dates, "suma los recolectados", "calcula el porcentaje de rotura") — GuardrailsEntradaRegresionTest RED (27 failed) -> GREEN, suite 243/243
Final: fixed #2 ScopeEnforcement false positives (programación de vacunación, liga de proveedores) — same test RED->GREEN
Final: fixed #3 PersonaDrift "cambia el nombre de la casita" — same test RED->GREEN
Final: fixed #4 obvious sums bypass ("cuanto da 23 mas 45", "23 por 45", "dos más dos", "raiz de 144") — same test RED->GREEN
Final: fixed #5 store races (stale responses, loading flag, detalle of wrong log, double fetch from rankings) — verified by build + code only: the frontend has no test runner, so no RED->GREEN test exists for it
Final: fixed #6 load errors now shown via existing BaseAlert — build only, same caveat
Final: minor (deferred): #7 PillTabs role=tablist without tabpanel/aria-controls/arrow keys
Final: minor (deferred): #8 LogDetalleModal uses amber-*/red-* Tailwind colors instead of DESIGN.md tokens
Final: minor (deferred): #9 any authenticated user reads all operator messages + full prompt (accepted in spec: no roles yet)
Final: minor (deferred): #10 ToolArgumentValidationGuard stricter than Carbon (2026-11-9, "Gallinas", "Pavo" rejected); retries consume loop iterations
Final: minor (deferred): #11 DateFormatGuard also rewrites date-shaped codes ("lote 2026-11-09-A")
Final: Ruling: kept the bypass "number + farm word passes" (e.g. "cuánto es 23 + 45 huevos") — spec accepts it as a conservative guard whose false negatives are tuned from the Benchmark; cost if wrong: that phrasing reaches the model.
