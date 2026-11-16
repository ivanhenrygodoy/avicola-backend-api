# SDD: Módulo de Apariencia y Modo Nocturno — Administración Dinámica de Paleta

**Estado:** Finalizado con éxito

> **Estado:** Implementado y verificado.

**Goal:** Crear el módulo **Apariencia** (`/apariencia`) para administrar dinámicamente toda la paleta del sistema (botones, formularios, cajas de texto, filtros, ribetes, contornos, sombras, tarjetas, navegación, fondos y badges) precargando los valores actuales de `DESIGN.md`, e incorporar la **Sección de Nocturnidad** mediante un switch píldora en la barra principal (`AppHeader.vue`) que adecúe toda la paleta al modo nocturno orgánico.

---

## 1. Especificación (SDD Spec)

### 1.1 Requisitos Funcionales
- **RF-01 (Valores por defecto idénticos al sistema actual):** `TemaDefaultSeeder` precarga los valores actuales de `DESIGN.md` y `src/style.css` para **Modo Claro**, y una paleta orgánica coherente para **Modo Nocturno**.
- **RF-02 (Switch de Nocturnidad en `AppHeader.vue`):**
  - Componente modular `ThemeToggle.vue` ubicado en `AppHeader.vue`, inspirado en el switch píldora de referencia:
    - **Modo Claro:** pista en Crema (`bg-crema border-borde`), knob circular deslizante a la izquierda con icono `Sun` (Lucide) y texto *"Modo Claro"* (oculto en móvil compacto).
    - **Modo Nocturno:** pista en Bosque Nocturno profundo (`#0F1713` / `#16221C`) con micro-destellos en Salvia (`#6F9678`) — respetando `DESIGN.md` sin azules ni morados genéricos —, knob circular deslizante a la derecha con icono `Moon` (Lucide) y texto *"Modo Nocturno"*.
    - Transición fluida con `motion-reduce:transition-none` y `role="switch"` + `aria-checked`.
- **RF-03 (Adecuación Completa de Paleta Nocturna):**
  - Al activar el Modo Nocturno (`data-theme="nocturno"` en `<html>`), toda la aplicación adapta automáticamente sus variables CSS:
    - Fondo general (`crema`): `#0F1713`
    - Superficie tarjetas / navbar / sidebar / modales / inputs (`blanco`): `#16221C`
    - Superficie alternativa (`crema-surface` / hover): `#1D2B24`
    - Contornos / bordes (`borde`): `#25382E`
    - Tinta principal (`verdoso`): `#E8EFEA` | Tinta secundaria (`verdoso-muted`): `#9BB2A4`
    - Acento primario (`bosque`): `#3B7A57` (hover `#2F6346`) | Secundario (`salvia`): `#7AA885`
    - Ribete de foco (`ring`), sombras elevadas (`shadow-xs`), badges/alertas verdes (`--avicola-exito-bg` `#1B3B2B`, `--avicola-exito-text` `#86EFAC`) e inactivos (`#451A1A` / `#FCA5A5`) ajustados para garantizar contraste WCAG AA/AAA.
    - **Transición suave y amigable:** Al alternar modo, `temaStore.alternarModoNocturno()` activa temporalmente `html.theme-transitioning` (`720ms` con *reflow* forzado) y aplica una curva gradual (`650ms cubic-bezier(0.25, 0.8, 0.25, 1)` en superficies/bordes/sombras, `600ms` en texto/iconos y `500ms ease-in-out` en `ThemeToggle.vue`), respetando `prefers-reduced-motion: reduce`.
    - **Contraste del isotipo en Chatbot:** La silueta/ribete de la gallina (`GallinaIcon` y `GallinaCaminando`) usa `.gallina-nocturna-blanca` para mostrarse en blanco nítido (`#FFFFFF`) dentro del botón flotante y el indicador del asistente cuando el modo nocturno está activo.
- **RF-04 (Selector de Componentes y Edición por Modo en `/apariencia`):**
  - El administrador (`super_admin`) elige entre los 9 grupos de componentes (*Botones*, *Formularios*, *Cajas de Texto y Filtros*, *Contornos y Ribetes*, *Sombras y Elevación*, *Tarjetas y Contenedores*, *Navegación*, *Fondos y Tipografía*, *Badges y Alertas*).
  - Incluye `PillTabs` para alternar qué paleta editar (**Modo Claro** | **Modo Nocturno**), editando sus aspectos visuales con selector de color + `FormField` hex y una **vista previa en vivo** (`AparienciaPreview.vue`).
- **RF-05 (Persistencia y Restauración):**
  - El tema activo se carga al iniciar la app (`GET /api/v1/tema/activo`).
  - La preferencia personal de nocturnidad se guarda en `localStorage` y en `mnt_usuarios.modo_tema` (`PATCH /api/v1/auth/preferencia-tema`).
  - Restauración por componente o global a `valor_defecto` con confirmación `BaseConfirmModal`.

---

## 2. Arquitectura de Base de Datos y Backend (`avicola-backend-api`)

| Tabla | Prefijo | Columnas principales | Propósito |
|---|---|---|---|
| `ctl_componentes_tema` | `ctl_` | `id`, `clave` (unique), `nombre`, `descripcion`, `icono`, `orden`, `activo`, `timestamps` | Catálogo maestro de los 9 grupos de componentes configurables. |
| `mnt_configuracion_tema` | `mnt_` | `id`, `id_componente_tema` (FK `restrictOnDelete()`), `modo` (`claro`\|`nocturno`), `aspecto`, `etiqueta`, `variable_css`, `tipo_control` (`color`\|`shadow`), `valor_actual`, `valor_defecto`, `timestamps` | Aspectos visuales de cada componente por modo. Índice único `['id_componente_tema', 'modo', 'aspecto']`. |
| `mnt_usuarios` (alter) | `mnt_` | `+ modo_tema` (`string(20)`, default `'claro'`) | Preferencia personal de modo claro/nocturno devuelta en `/auth/login` y `/auth/me`. |

- **Módulo y Permisos:** `apariencia` en `RolesYModulosSeeder` (`solo_super => true`, `ruta => '/apariencia'`, `icono => 'Palette'`).
- **Endpoints (`/api/v1/`):**
  - `GET /tema/activo`
  - `PATCH /auth/preferencia-tema` (`auth:sanctum`)
  - Bajo `middleware(['auth:sanctum', 'modulo:apariencia'])`:
    - `GET /apariencia/componentes`
    - `GET /apariencia/componentes/{componente}`
    - `PUT /apariencia/componentes/{componente}` (`DB::transaction()`)
    - `POST /apariencia/componentes/{componente}/restaurar`
    - `POST /apariencia/restaurar-todo`

---

## 3. Arquitectura Frontend (`avicola-frontend`)

- **`src/style.css` y `tailwind.config.js`:** Variables CSS en canales RGB en `:root` (claro) y `[data-theme='nocturno']` (nocturno, aplicable también al contenedor aislado de vista previa), vinculadas a Tailwind mediante `rgb(var(--avicola-*) / <alpha-value>)`, más variables para sombras (`--avicola-shadow-xs`), ribetes (`--avicola-ring-focus`), formularios (`.avicola-form-field`), alertas/badges de éxito (`.avicola-badge-success`, `.avicola-alert-success`) y transición suave `html.theme-transitioning`.
- **`src/services/tema.js` y `src/stores/tema.js`:** Carga inicial en `main.js`, aplicación de `data-theme` en `<html>`, inyección de variables personalizadas y acción `alternarModoNocturno()`.
- **`src/components/common/ThemeToggle.vue`:** Switch píldora animado (`500ms ease-in-out`) integrado en `AppHeader.vue`.
- **`src/views/AparienciaView.vue` + `AparienciaPreview.vue` + `AspectoEditorField.vue`:** Pantalla administrable con `provideActionLoader()` + `<ActionLoader>`, selector de componentes, `PillTabs` (*Modo Claro* / *Modo Nocturno*), editor de aspectos y vista previa en vivo.

---

## 4. Registro de Tareas y Verificación

### Fase 1: Backend (`avicola-backend-api`)
- [x] **Task 1:** Migraciones `ctl_componentes_tema`, `mnt_configuracion_tema` y columna `modo_tema` en `mnt_usuarios` ejecutadas.
- [x] **Task 2:** `TemaDefaultSeeder` (9 componentes con valores `DESIGN.md` claro + paleta orgánica nocturna) y módulo `apariencia` en `RolesYModulosSeeder`.
- [x] **Task 3:** Modelos (`ComponenteTema`, `ConfiguracionTema`, `User`), FormRequests (`UpdateComponenteTemaRequest`, `UpdatePreferenciaTemaRequest`), `TemaService`, controladores (`TemaPublicoController`, `TemaAdminController`) y rutas en `routes/api.php`.
- [x] **Task 4:** `tests/Feature/TemaTest.php` + actualización de `AuthPermisosTest` y `RolesYModulosTest`.

### Fase 2: Motor Dinámico y Switch de Nocturnidad (`avicola-frontend`)
- [x] **Task 5:** `src/style.css` y `tailwind.config.js` refactorizados a CSS Custom Properties (modo claro + `:root[data-theme='nocturno']`).
- [x] **Task 6:** `src/services/tema.js` y `src/stores/tema.js` inicializados en `src/main.js` y sincronizados en `src/stores/auth.js`.
- [x] **Task 7:** `src/components/common/ThemeToggle.vue` integrado en `src/components/common/AppHeader.vue`.

### Fase 3: Pantalla de Apariencia y Verificación
- [x] **Task 8:** `AparienciaPreview.vue`, `AspectoEditorField.vue`, `AparienciaView.vue`, ruta `/apariencia` en `src/router/index.js` e icono `Palette` en `AppSidebar.vue`.
- [x] **Task 9:** Plan SDD guardado en `docs/plans/2026-11-14-modulo-apariencia-nocturno.md` de ambos repositorios.
- [x] **Task 10 (Verificación):**
  - `npm --prefix avicola-frontend run build` -> `✓ built in 4.44s` (0 errores).
  - `docker exec avicola_api php artisan db:seed --class=TemaDefaultSeeder --force` -> `DONE`.
  - `docker exec avicola_api php artisan test tests/Feature/TemaTest.php` -> `5 passed (20 assertions)` (y `21 passed (91 assertions)` junto a `AuthPermisosTest` y `RolesYModulosTest`).

