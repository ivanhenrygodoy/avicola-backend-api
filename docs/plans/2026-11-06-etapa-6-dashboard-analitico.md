# Etapa 6: Dashboard Analítico Implementation Plan

**Estado:** Finalizado con éxito

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un único endpoint `GET /api/v1/dashboard/resumen` calculado en BD y un dashboard Vue que lo consume con una sola petición.

**Architecture:** Cada indicador es una clase que implementa `IndicadorDashboard` (SRP). `DashboardResumenService` recibe la colección de indicadores por inyección (DIP/OCP: añadir uno no modifica los demás). El controlador es delgado. En el frontend: servicio → store Pinia → vista orquestadora → tarjetas por sección, con gráficos SVG propios y reutilizables en `common/`.

**Tech Stack:** Laravel 13 / PHP 8.3 / PostgreSQL 16 / PHPUnit 12 (en Docker `avicola_api`); Vue 3 + Pinia + Tailwind 3 + lucide-vue-next.

**Spec:** `README.md` §Etapa 6, `avicola-frontend/DESIGN.md`, skills `.agents/skills/avicola-{backend,frontend,design-system}`. Decisiones aprobadas por defecto: fuente de sistema (DESIGN.md), gráficos SVG propios, ocupación sin porcentaje, feed + 3 alertas.

## Global Constraints

- **No hacer commits** hasta que el usuario lo indique. Los pasos "Commit" no se ejecutan; solo se deja el árbol de trabajo listo.
- Tests con `docker compose exec api php artisan test ...` (regla del CLAUDE.md global). Linters (Pint) solo sobre archivos tocados.
- BD solo SELECT. Nombres: tablas `ctl_`/`mnt_`, FKs `id_<entidad>`. No se agregan migraciones.
- Respuesta JSON `{ "success": true, "data": {...} }`. Fechas serializadas `d-m-Y` en listados; en el dashboard, series con `fecha` ISO `Y-m-d` (documentado, es para ejes de gráfico).
- Zona horaria de negocio única: `America/El_Salvador` vía `config('avicola.timezone')`. "Hoy" y "mes actual" se calculan con ella, no con UTC.
- Kilos de alimento: solo registros con `unidad_medida = 'kg'`. Los demás no se suman.
- Solo aves con `estado = 'activa'` (`Ave::activa()`).
- Frontend: tokens `bosque/salvia/crema/huevo/tierra/verdoso/borde`; tarjetas `rounded-2xl border border-borde shadow-xs`; sin tarjetas anidadas, sin emojis, íconos Lucide; `space-y-6`; reutilizar `StatCard`, `AppHeader`, `BaseAlert`.
- Respuestas de error del frontend con `useNotificationStore`; nunca `alert()`.

## Review Focus

- Base vacía (sin aves ni registros): todos los totales en 0, series rellenas con 0, sin error 500.
- Registro a las 23:30 hora El Salvador (ya es día siguiente en UTC) cuenta en el día correcto.
- Alimentación con `unidad_medida` distinta de `kg` no infla el total de kilos.
- Aves `fallecida/salida/trasladada` no cuentan en población ni ocupación.
- División sin aves aparece en ocupación con 0; división inactiva se marca `activa: false`.
- `dias` inválido (`15`, `abc`) → 422, no 500.

## File Structure

Backend (`avicola-backend-api/`):
- Create `config/avicola.php` — `timezone`.
- Create `app/Services/Dashboard/Contracts/IndicadorDashboard.php` — contrato.
- Create `app/Services/Dashboard/PeriodoDashboard.php` — value object (hoy, inicio de mes, rango `dias`).
- Create `app/Services/Dashboard/Indicadores/{AvesResumen,OcupacionResumen,ProduccionHuevosResumen,AlimentacionResumen,GastosResumen,AlertasResumen,ActividadResumen}.php`.
- Create `app/Services/Dashboard/DashboardResumenService.php`.
- Create `app/Http/Controllers/Api/V1/DashboardController.php`, `app/Http/Requests/Dashboard/ResumenDashboardRequest.php`.
- Modify `app/Providers/AppServiceProvider.php` (tag/bind indicadores), `routes/api.php`.
- Create `tests/Feature/DashboardTest.php`.

Frontend (`avicola-frontend/src/`):
- Create `services/dashboard.js`, `stores/dashboard.js`.
- Create `components/common/{BarChart,ProgressBar}.vue`.
- Create `components/dashboard/{DashboardKpiGrid,AvesDistribucionCard,OcupacionCasitasCard,ProduccionHuevosCard,GastosCategoriaCard,AlertasActividadCard}.vue`.
- Modify `views/DashboardView.vue`.

## Contrato de respuesta (`data`)

```
aves:        { total, por_especie:[{especie,total}], por_sexo:[{sexo,total}] }
ocupacion:   [{ id_casita, casita, total_aves, divisiones:[{id_division,division,activa,total_aves,especies:[string]}] }]
huevos:      { hoy:{recolectados,rotos,aprovechables}, rango:{dias,recolectados,rotos,aprovechables,porcentaje_rotura},
               serie:[{fecha:'Y-m-d',aprovechables,rotos}]   // exactamente `dias` elementos, cronológico
               ultimos_7:{aprovechables,rotos}, ultimos_30:{aprovechables,rotos} }
alimentacion:{ kilos_mes, kilos_hoy }
gastos:      { total_mes, por_categoria:[{categoria,total}] }
alertas:     [{ codigo:'sin_postura_hoy'|'rotura_alta'|'sin_alimentacion_hoy', nivel:'warning', mensaje }]
actividad:   [{ tipo:'traslado'|'huevos'|'gasto'|'observacion', fecha:'Y-m-d H:i', descripcion }]  // máx 10, más reciente primero
```
Query param opcional `dias` ∈ {7, 30} (default 7) solo gobierna `huevos.rango` y `huevos.serie`.

---

### Task 1: Esqueleto del endpoint, contrato y periodo

**Files:**
- Create: `config/avicola.php`, `app/Services/Dashboard/Contracts/IndicadorDashboard.php`, `app/Services/Dashboard/PeriodoDashboard.php`, `app/Services/Dashboard/DashboardResumenService.php`, `app/Http/Controllers/Api/V1/DashboardController.php`, `app/Http/Requests/Dashboard/ResumenDashboardRequest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `routes/api.php`
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:**
- Produces: `interface IndicadorDashboard { public function clave(): string; public function calcular(PeriodoDashboard $periodo): array; }`
- Produces: `PeriodoDashboard::desde(int $dias = 7): self` con propiedades públicas readonly `Carbon $hoy`, `Carbon $inicioMes`, `Carbon $finMes`, `Carbon $inicioRango`, `int $dias`; todas en `config('avicola.timezone')`.
- Produces: `DashboardResumenService::__construct(iterable $indicadores)`; `resumen(int $dias): array` → `[clave => calcular()]`.
- Produces: ruta `GET /api/v1/dashboard/resumen` bajo `auth:sanctum` → `DashboardController@resumen`.

- [ ] **Step 1: Write failing tests** in `DashboardTest` (RefreshDatabase, `Sanctum::actingAs`, mismos seeders que `SeguimientoGastosTest`): `test_requiere_autenticacion` (401 sin token), `test_base_vacia_devuelve_ceros_sin_error` (aves.total 0, serie de 7 elementos todos 0, `alimentacion.kilos_mes` 0, `alertas` es array), `test_dias_invalido_devuelve_422` (`?dias=15` y `?dias=abc`), `test_dias_30_devuelve_serie_de_30`.
- [ ] **Step 2: Run** `docker compose exec api php artisan test --filter=DashboardTest` → FAIL (404/ruta inexistente).
- [ ] **Step 3: Implement** `config/avicola.php` (`'timezone' => env('AVICOLA_TIMEZONE', 'America/El_Salvador')`), `PeriodoDashboard`, servicio, `ResumenDashboardRequest` (`dias` → `nullable|integer|in:7,30`), controlador delgado (`response()->json(['success'=>true,'data'=>$service->resumen($request->integer('dias', 7))])`), registro en `AppServiceProvider` con `$this->app->tag([...], 'dashboard.indicadores')` y `when(DashboardResumenService::class)->needs('$indicadores')->giveTagged('dashboard.indicadores')`; ruta. En esta tarea los indicadores aún están vacíos: los 4 tests de esqueleto que dependen de claves reales se completan en Tasks 2-4, por lo que aquí solo deben pasar 401 y 422; marcar los otros dos con `markTestIncomplete` hasta Task 3.
- [ ] **Step 4: Run** → 401 y 422 PASS.
- [ ] **Step 5: Commit (diferido: no ejecutar hasta indicación del usuario).**

### Task 2: Aves y ocupación

**Files:**
- Create: `Indicadores/AvesResumen.php` (clave `aves`), `Indicadores/OcupacionResumen.php` (clave `ocupacion`)
- Modify: `AppServiceProvider.php` (agregar al tag)
- Test: `tests/Feature/DashboardTest.php`

**Interfaces:** Consumes `IndicadorDashboard`, `PeriodoDashboard` (Task 1). Produces claves `aves` y `ocupacion` con la forma del contrato.

- [ ] **Step 1: Write failing tests:** `test_aves_cuenta_solo_activas_por_especie_y_sexo` (crear 3 activas + 1 `fallecida` + 1 `salida`; assert `aves.total == 3`, suma de `por_especie` == 3, suma de `por_sexo` == 3); `test_ocupacion_incluye_divisiones_vacias_e_inactivas` (división sin aves → `total_aves 0`; división `activa=false` → `activa false`; `casita.total_aves` == suma de sus divisiones); `test_ocupacion_no_cuenta_aves_no_activas`.
- [ ] **Step 2: Run** `--filter=DashboardTest` → FAIL.
- [ ] **Step 3: Implement** ambas clases con agregados SQL (`join ctl_especie/ctl_sexo`, `groupBy`, `selectRaw count`; ocupación: una consulta de divisiones con `leftJoin` a aves activas y `string_agg(distinct especie)` o una segunda consulta agrupada, sin N+1) y registrarlas en el tag.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit (diferido).**

### Task 3: Producción de huevos

**Files:** Create `Indicadores/ProduccionHuevosResumen.php` (clave `huevos`); Modify `AppServiceProvider.php`; Test `DashboardTest.php`.

**Interfaces:** Produce clave `huevos` con la forma del contrato. Serie con exactamente `periodo->dias` elementos rellenando días sin registro con 0 (relleno en PHP sobre el resultado de un `groupBy fecha`).

- [ ] **Step 1: Write failing tests:** `test_huevos_serie_rellena_dias_sin_registro_con_cero` (un registro hace 2 días; `serie` tiene 7 elementos, 6 con 0); `test_huevos_totales_coinciden_con_tabla` (suma de `mnt_registros_huevos` del rango == `rango.recolectados`; aprovechables == recolectados − rotos); `test_porcentaje_rotura` (10 recolectados, 2 rotos → `20.0`; sin registros → `0`); `test_huevos_hoy_usa_zona_horaria_de_negocio` (`Carbon::setTestNow('2026-11-06 05:30:00 UTC')` = 23:30 del 05 en El Salvador; registro con fecha `2026-11-05` cuenta en `hoy`); `test_ultimos_7_y_30_son_independientes_de_dias`. Quitar los `markTestIncomplete` de Task 1.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** con una consulta agrupada por fecha cubriendo los últimos 30 días (de la que se derivan `ultimos_7`, `ultimos_30`, `hoy`, `rango` y `serie`), evitando 4 consultas separadas.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit (diferido).**

### Task 4: Alimentación y gastos

**Files:** Create `Indicadores/AlimentacionResumen.php` (`alimentacion`), `Indicadores/GastosResumen.php` (`gastos`); Modify `AppServiceProvider.php`; Test `DashboardTest.php`.

- [ ] **Step 1: Write failing tests:** `test_kilos_ignora_unidades_distintas_de_kg` (registros 5 kg y 3 `lb` → `kilos_mes == 5`); `test_kilos_hoy_vs_mes`; `test_gastos_mes_excluye_otros_meses_y_agrupa_por_categoria` (gasto del mes anterior no suma; `por_categoria` suma == `total_mes`; orden descendente por total).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** ambas con `sum`/`groupBy` y fechas de `PeriodoDashboard`; `por_categoria` con join a `ctl_categorias_gastos`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit (diferido).**

### Task 5: Alertas y actividad reciente

**Files:** Create `Indicadores/AlertasResumen.php` (`alertas`), `Indicadores/ActividadResumen.php` (`actividad`); Modify `AppServiceProvider.php`; Test `DashboardTest.php`.

**Interfaces:** `AlertasResumen` define const `UMBRAL_ROTURA = 10.0` (porcentaje, rango de 7 días). Alertas: `sin_postura_hoy` (hay aves activas y 0 registros de huevos hoy), `rotura_alta` (rotura 7 días > umbral), `sin_alimentacion_hoy` (hay aves activas y 0 registros hoy). Sin aves activas → sin alertas de ausencia.

- [ ] **Step 1: Write failing tests:** `test_alerta_sin_postura_hoy_solo_si_hay_aves_activas`; `test_alerta_rotura_alta_sobre_umbral` (11% sí, 10% exacto no); `test_sin_alertas_en_base_vacia`; `test_actividad_mezcla_tipos_ordenada_y_limitada_a_10` (crear 12 eventos de 4 tipos; 10 resultados, descendente por fecha).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Actividad: 4 consultas `limit(10)` (movimientos, huevos, gastos, observaciones) unificadas y ordenadas en PHP, con `descripcion` en español legible.
- [ ] **Step 4: Run** `docker compose exec api php artisan test` completo → todo PASS. Pint solo sobre archivos creados/modificados.
- [ ] **Step 5: Commit (diferido).**

### Task 6: Servicio y store del frontend

**Files:** Create `src/services/dashboard.js`, `src/stores/dashboard.js`.

**Interfaces:** Produce `getResumen(params = {}): Promise<object>` (devuelve `response.data.data`); store `useDashboardStore` con state `{ resumen: null, loading: false, error: null, dias: 7 }`, getters `hayDatos`, action `fetchResumen()` (usa `this.dias`) y `setDias(n)` que refetchea. Patrón idéntico a `stores/gastos.js`.

- [ ] **Step 1: Implement** servicio y store; en error `this.error = err` y `console.error`, sin `alert`.
- [ ] **Step 2: Verify** con `npm run build` (sin errores) y llamando `getResumen()` desde el navegador autenticado: respuesta con las 7 claves del contrato.
- [ ] **Step 3: Commit (diferido).**

### Task 7: Componentes de gráfico reutilizables

**Files:** Create `src/components/common/BarChart.vue`, `src/components/common/ProgressBar.vue`.

**Interfaces:**
- `BarChart` props: `series: [{label:string, value:number, value2?:number}]`, `color` (default `#D9A441`), `color2` (default `#B91C1C`), `height` (default 160), `ariaLabel: string`; SVG con `role="img"`, eje X con etiquetas cortas, y `<table class="sr-only">` con los datos. Con todos los valores en 0 muestra línea base, no divide por 0.
- `ProgressBar` props: `value`, `max`, `colorClass` (default `bg-bosque`), `label`; `role="progressbar"` con `aria-valuenow/max`; `max = 0` → 0%.

- [ ] **Step 1: Implement** ambos con tokens de DESIGN.md.
- [ ] **Step 2: Verify** visualmente con datos de prueba en una vista temporal (borrarla después) incluyendo caso todo-ceros y un valor único.
- [ ] **Step 3: Commit (diferido).**

### Task 8: Tarjetas del dashboard

**Files:** Create los 6 componentes en `src/components/dashboard/`.

**Interfaces:** cada tarjeta recibe solo la porción que necesita (ISP): `DashboardKpiGrid` ← `{resumen}` (usa `StatCard`: Población de aves, Huevos aprovechables hoy, Alimento del mes, Gastos del mes; 4 columnas `lg:grid-cols-4`); `AvesDistribucionCard` ← `aves`; `OcupacionCasitasCard` ← `ocupacion` (casita con `ProgressBar` relativo al total de aves, divisiones listadas con conteo y chips de especie; división inactiva con badge `#FEE2E2/#991B1B`); `ProduccionHuevosCard` ← `huevos`, `dias`, emite `update:dias` (toggle 7/30 accesible); `GastosCategoriaCard` ← `gastos` (`ProgressBar` por categoría, color `tierra`); `AlertasActividadCard` ← `alertas`, `actividad` (alertas con estilo warning de `BaseAlert`; actividad como lista con ícono Lucide por tipo).
Cada tarjeta maneja estado vacío con texto explicativo (no se deja en blanco).

- [ ] **Step 1: Implement** cada componente sin lógica de red, sin tarjetas anidadas (las filas internas usan divisores `border-borde`, no cajas).
- [ ] **Step 2: Verify** `npm run build`.
- [ ] **Step 3: Commit (diferido).**

### Task 9: Refactor de `DashboardView` y verificación visual

**Files:** Modify `src/views/DashboardView.vue`.

- [ ] **Step 1: Refactor:** `onMounted` pasa de 5 peticiones (+ usuario) a `dashboardStore.fetchResumen()` (+ `fetchCurrentUser` si falta). Quitar imports de stores de instalaciones/aves/producción/gastos si ya no se usan. Layout: encabezado actual → `DashboardKpiGrid` → alertas/actividad → grid `lg:grid-cols-3` (producción 2 col + aves 1 col) → grid `lg:grid-cols-2` (ocupación, gastos) → módulos del sistema compactados como accesos rápidos (sin contadores que requerían otras peticiones). Estado `loading` con `ActionLoader` o skeleton; error con `BaseAlert` + botón reintentar.
- [ ] **Step 2: Verify en navegador** (Playwright/Chrome DevTools MCP) con la API en Docker: (a) una sola petición de red a `/dashboard/resumen`; (b) KPIs == SELECT directos a `mnt_` (consultar con el método de BD que indique el usuario); (c) responsive 375 / 768 / 1280; (d) estado vacío y estado de error (apagar API); (e) sin errores en consola; (f) toggle 7/30 días refetchea.
- [ ] **Step 3: Run** `docker compose exec api php artisan test` completo → PASS.
- [ ] **Step 4: Commit (diferido hasta indicación del usuario).**
