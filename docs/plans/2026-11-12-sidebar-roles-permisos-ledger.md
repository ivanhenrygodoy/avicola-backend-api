# Ledger — Sidebar, roles, permisos y usuarios

**Estado:** Finalizado con éxito

Plan: `2026-11-12-sidebar-roles-permisos.md` · Spec: `../specs/2026-11-12-sidebar-roles-permisos-design.md` (D1–D9, R1–R22)
Ejecutado el 2026-11-13 de forma inline, sin subagentes por tarea y **sin commits** (el usuario los ordena).

## Estado por tarea

| Tarea | Estado | Evidencia |
|---|---|---|
| 1 Roles, módulos, permisos y columnas | Completa | `RolesYModulosTest` 8/8; migrada en dev (admin = `super_admin`; aves 14 / gastos 6 / huevos 10 / alimentación 3 sin cambios; 9 módulos; operario con 6 base) |
| 2 Middleware `modulo:<clave>` | Completa | `ProteccionPorModuloTest` 50/50 (20 fallaban antes); 48 rutas antes y después |
| 3 Login, `me` y desactivados | Completa | `AuthPermisosTest` 8 + `AuthTest` intacto |
| 4 API de permisos | Completa | `PermisosApiTest` 11 |
| 5 Usuarios y correo por cola | Completa en código | `UsuariosApiTest` 23; job probado en navegador con SMTP real a Mailpit |
| 6 Contraseña y primer ingreso | Completa | `PasswordApiTest` 9 |
| 7 Auth store y servicios | Completa | `vite build` |
| 8 Sidebar y layout | Completa | `vite build` + recorrido en navegador (escritorio y móvil) |
| 9 Guardia de rutas y 403 | Completa | navegador: URL prohibida, 403 a mitad de sesión |
| 10 Pantallas de usuarios y permisos | Completa | navegador: alta, reenvío, primer ingreso, cambio de contraseña |
| 11 Ajuste visual y documentación | **Documentación hecha; ajuste visual pendiente** | README de ambos repos; el ajuste fino de R8/D7 lo pidió el usuario al final |

Suite backend aislada: **469 passed** (línea base 360). Frontend: `vite build` → `✓ built`.

## Rulings

- **Sin commits en todo el plan** — el usuario lo exige; no hay rango por tarea. Costo: la revisión final se hizo sobre `git status`/`git diff` de ambos repos.
- **Repos separados, sin worktree** — el usuario trabaja directo en `main`. Costo: cambios sin aislar.
- **Laravel Boost no se instala** — lo pide el `CLAUDE.md` del backend, pero el usuario eligió no instalarlo. Costo: ninguno.
- **BD de dev por `docker exec postgres`** — el servicio `postgres` de este compose no corre; la API usa `DB_HOST=192.168.208.1` hacia un contenedor `postgres` suelto. Costo: ninguno.
- **Servicio `queue` en `docker-compose.yml` sin `depends_on` y sin levantarlo** — el contenedor `avicola_api` en ejecución no coincide con el compose del repo (otra red y otro `DB_HOST`); `up -d queue` con `depends_on` arrancaría otro Postgres o recrearía `api`. Costo: **el worker no está corriendo**; hay que arrancarlo a mano o alinear el compose.
- **El job guarda la temporal antes de enviar** — evita un correo con una clave que no sirve. Costo: si se agotan los reintentos la cuenta queda con una clave desconocida; se resuelve con «Reenviar credenciales».
- **Reenviar credenciales a uno mismo o a un desactivado → 422** (la spec no lo dice) — el superAdmin se invalidaría su propia clave y sesión. Costo: cambia su clave por el flujo de contraseña.
- **`PrimerIngresoModal` maneja Escape con un listener propio** — `BaseModal` (global) no lo hace y el plan prohíbe alterar globales. Costo: ninguno.
- **Sidebar con `z-[35]`/`z-[34]`** — por debajo de los modales (`9999`) y sin tapar el chat (`40`) en móvil. Costo: ninguno.
- **Guardia sin ningún módulo permitido → login** — evita un bucle con un usuario sin rol. Costo: ve el login con aviso en lugar de una pantalla vacía.
- **`main.js` pide `me` antes de montar el router** — corrige un error hallado en el navegador: el operario con Benchmark otorgado era rebotado al Dashboard por permisos obsoletos en `localStorage`. Costo: una petición más al arrancar con sesión.

## Hallazgos corregidos durante la verificación

- Interpolación `"…$clave»…"` en `PermisosService` daba 500 en vez de 422 (test en rojo → verde).
- El botón flotante del chat tapaba «Guardar» y la última fila en `PermisosView` y `UsuariosView` (`pb-28`).
- La marca de agua del sidebar generaba scroll horizontal (`overflow-x-hidden`).

## Pendiente

- Worker de la cola sin levantar (ver ruling).
- Ajuste visual del sidebar con el usuario (Tarea 11, Step 1).
- Revisión final con contexto limpio: ver mensaje de cierre.
