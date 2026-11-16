# Ajustes visuales del chat AviaCore — Plan de implementación

**Estado:** Finalizado con éxito

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el chat muestre «AviaCore está pensando…» con el logo del proyecto, que el historial use siempre «Eliminar» en vez de «Quitar», y que el historial vacío quede centrado.

**Architecture:** Tres ajustes de interfaz en `avicola-frontend` sin lógica nueva ni componentes globales tocados. El backend ya responde «Conversación eliminada del historial.»; solo se fija ese texto con una prueba más y se corrigen comentarios y README.

**Tech Stack:** Vue 3 + Tailwind 3 + lucide-vue-next (frontend, sin corredor de pruebas: `vite build` + navegador); Laravel 13 / PHPUnit (backend, Docker).

**Spec:** `docs/superpowers/specs/2026-11-13-ajustes-visuales-chat-design.md` (aprobada por el usuario).

## Global Constraints

- Paleta: bosque `#244B36`, salvia `#6F9678`, crema `#F5F3EA`, verdoso `#26332B`, borde `#E4E7DF`; fuente del sistema; sin dependencias nuevas.
- No alterar componentes globales (`EmptyState`, `BaseModal`, `BaseConfirmModal`, `GallinaIcon`).
- Texto del estado «pensando»: **fijo** → `AviaCore está pensando…` (con el carácter `…`, no tres puntos sueltos).
- Avisos: éxito `Conversación eliminada del historial.`; error `No se pudo eliminar la conversación del historial.`
- Botón de confirmar `Sí, eliminar`; título del modal `Eliminar del historial`; pregunta `¿Eliminar esta conversación de tu historial?`; `title` del botón de fila `Eliminar del historial`.
- La confirmación **no promete borrado permanente**: conserva «Dejará de aparecer en tu historial. Podrás empezar otra conversación cuando quieras.»
- «Quitar selección» de `FloatingSelect` y «quitar» de otros módulos **no se tocan**.
- Tests backend **solo**: `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test` (un `php artisan test` pelado vacía la BD de desarrollo).
- Pint solo sobre archivos tocados. **No hacer commit** hasta que el usuario lo ordene; el usuario hace el `push`. Mensajes de commit: Conventional Commits en español, sin tildes, con alcance.
- Archivos de texto del frontend con CRLF en Windows: editar con `Edit`, no con comandos que reescriban el archivo entero.

## Review Focus

- **Movimiento reducido:** con `prefers-reduced-motion`, el indicador no anima (ni pulso ni puntos) y el texto sigue legible (Tarea 1).
- **Lector de pantalla:** el indicador se anuncia una vez («AviaCore está pensando») y los puntos animados no se leen (Tarea 1).
- **Texto con prop:** quien pase `texto` a `AsistenteIndicador` sigue viendo su texto, no el nuevo por defecto (Tarea 1).
- **Dos sitios con «quitar» que no son el modal:** el store y el servicio traen comentarios con la palabra; no debe quedar ninguna referencia al historial con «quitar» (Tarea 2).
- **Historial con error de carga:** `errorHistorial` sin conversaciones también debe centrarse, no solo el vacío (Tarea 3).
- **Lista llena:** con conversaciones, el contenedor no debe centrar ni limitar la lista (Tarea 3).

---

## Task 1: Estado «AviaCore está pensando…»

**Files:**
- Modify: `avicola-frontend/src/components/asistente/AsistenteIndicador.vue`
- Modify: `avicola-frontend/src/components/asistente/AsistenteChatWidget.vue:201-203` (solo si el contenedor `pt-1` estorba; la llamada `<AsistenteIndicador />` no cambia)
- Modify: `avicola-frontend/README.md` (línea de `AsistenteIndicador.vue` en 6.7)

**Interfaces:**
- Produces: `AsistenteIndicador` con la misma API: prop `texto: String`, valor por defecto `'AviaCore está pensando…'`. Sin eventos ni slots nuevos.

- [ ] **Step 1: Reproducir el estado actual en el navegador.** Levanta `npx vite --port 5173` en `avicola-frontend`, inicia sesión, abre el chat y envía una pregunta; con la pestaña de red en «Slow 3G» (o frenando la API) captura la píldora con spinner. Expected: se ve «Consultando datos de la granja...» con un círculo girando. Es la línea base.
- [ ] **Step 2: Reescribir `AsistenteIndicador.vue`.** Contenedor `role="status"` y `aria-live="polite"`; a la izquierda un círculo (`w-8 h-8 rounded-full bg-bosque-50 border border-salvia/40`) con `GallinaIcon` (`w-5 h-5 text-bosque`) y `animate-pulse motion-reduce:animate-none`; a la derecha el texto en `text-xs font-medium text-verdoso`, seguido de tres `<span aria-hidden="true">` de punto (`w-1 h-1 rounded-full bg-salvia`) con `animate-bounce motion-reduce:animate-none` y `[animation-delay:0ms|150ms|300ms]`. El texto por defecto es `AviaCore está pensando…` **sin** los puntos animados duplicados: el `…` del texto se muestra solo cuando `motion-reduce` está activo (clase `hidden motion-reduce:inline`) y los puntos animados llevan `motion-reduce:hidden`, para que nunca se vean ambos.
- [ ] **Step 3: Verificar el build.** Run: `cd avicola-frontend && npx vite build`. Expected: `✓ built`, sin errores ni avisos nuevos.
- [ ] **Step 4: Verificar en el navegador (movimiento normal).** Repite el Step 1. Expected: el círculo con la gallina pulsa suave, el texto dice «AviaCore está pensando» y los tres puntos suben en secuencia; sin scroll horizontal; el chat flotante y los modales siguen por encima (z-index intacto).
- [ ] **Step 5: Verificar con «reducir movimiento».** En Chrome DevTools: Rendering → «Emulate CSS prefers-reduced-motion: reduce». Repite el Step 1. Expected: nada se mueve y el texto termina en `…` fijo, sin puntos animados.
- [ ] **Step 6: Verificar que la prop sigue mandando.** En consola de la página: monta el componente con `texto="Consultando datos de la granja..."` (o revisa que `AsistenteChatWidget` no pasa ninguna). Expected: con prop se ve el texto pasado; sin prop, el nuevo.
- [ ] **Step 7: Actualizar el README del frontend.** La línea de `AsistenteIndicador.vue` describe ahora «indicador de pensamiento con el logo, pulso y puntos en secuencia; respeta `prefers-reduced-motion`».

## Task 2: «Quitar» pasa a «Eliminar» en el historial

**Files:**
- Modify: `avicola-frontend/src/components/asistente/HistorialConversacionesModal.vue` (script y plantilla)
- Modify: `avicola-frontend/src/stores/asistente.js:189` (comentario)
- Modify: `avicola-frontend/src/services/asistente.js:35` (comentario)
- Modify: `avicola-frontend/README.md` (6.7: descripción del modal y la línea «Nada se borra nunca»)
- Modify: `avicola-backend-api/README.md:235` (fila del `DELETE`)
- Modify: `avicola-backend-api/app/Http/Controllers/Api/V1/AsistenteConversacionController.php:14` (comentario de clase, si dice «quita»)
- Test: `avicola-backend-api/tests/Feature/ConversacionAsistenteApiTest.php`

**Interfaces:**
- Consumes: `useAsistenteStore().eliminarConversacion(uuid)` (ya se llama así; no cambia).
- Produces: nada nuevo; solo texto y nombres internos.

- [ ] **Step 1: Fijar el texto del backend con una prueba.** En `ConversacionAsistenteApiTest`, `test_eliminar_cambia_el_estado_sin_borrar_filas` ya comprueba el mensaje. Añade `test_eliminar_no_usa_la_palabra_quitar_en_la_respuesta` que borre una conversación propia y haga `assertStringNotContainsStringIgnoringCase('quit', $r->json('message'))` además de `assertSame('Conversación eliminada del historial.', …)`.
- [ ] **Step 2: Ejecutar la prueba.** Run: `cd avicola-backend-api && docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test --filter=ConversacionAsistenteApiTest`. Expected: PASS (el backend ya cumple; la prueba es de regresión, y no hay código de producción que cambiar).
- [ ] **Step 3: Renombrar en `HistorialConversacionesModal.vue`.** `quitando` → `eliminando`, `quitandoEnCurso` → `eliminandoEnCurso`, `confirmarQuitar` → `confirmarEliminar`; aplica los textos de Global Constraints (título, pregunta, `confirm-text`, `title` del botón de fila, `notify.success` y `notify.error`, el `console.error` y el comentario inicial del archivo). No cambies `:is-destructive`, `description` ni la lógica.
- [ ] **Step 4: Actualizar los comentarios y el README.** `stores/asistente.js:189` y `services/asistente.js:35` pasan a «Elimina…»; en el README del frontend, el modal «cambiar nombre y eliminar del historial» y la línea «Nada se borra nunca» dice «eliminar del historial» solo cambia el estado y que la interfaz **no promete borrado permanente**; en el README del backend la fila del `DELETE` dice «La elimina del historial: solo cambia el estado, no borra nada».
- [ ] **Step 5: Comprobar que no queda ninguna referencia del historial.** Run: `grep -rniE "quitar|quita(r|n|da)|quitando" avicola-frontend/src/components/asistente avicola-frontend/src/stores/asistente.js avicola-frontend/src/services/asistente.js avicola-backend-api/app/Http/Controllers/Api/V1/AsistenteConversacionController.php`. Expected: sin coincidencias. (`FloatingSelect` y los comentarios de `FilterBar` y `GuardrailBase` quedan fuera, a propósito.)
- [ ] **Step 6: Verificar el build.** Run: `cd avicola-frontend && npx vite build`. Expected: `✓ built`.
- [ ] **Step 7: Verificar en el navegador.** Abre Historial, pulsa el icono de papelera de una fila. Expected: el `title` dice «Eliminar del historial»; el modal dice «Eliminar del historial», «¿Eliminar esta conversación de tu historial?» y el botón «Sí, eliminar»; al confirmar aparece «Conversación eliminada del historial.» y la fila desaparece de la lista. Fuerza un error (API detenida) y comprueba «No se pudo eliminar la conversación del historial.».

## Task 3: Historial vacío centrado

**Files:**
- Modify: `avicola-frontend/src/components/asistente/HistorialConversacionesModal.vue` (contenedor `pt-4 min-h-[8rem]` y plantilla)
- Modify: `avicola-frontend/README.md` (línea del modal, si menciona el estado vacío)

**Interfaces:**
- Consumes: `EmptyState` con `title`, `description`, `icon` (sin cambios); `asistenteStore.conversaciones`, `asistenteStore.errorHistorial`, `asistenteStore.cargandoHistorial`.

- [ ] **Step 1: Reproducir.** Con un usuario sin conversaciones (o tras eliminarlas todas), abre Historial. Expected: «Aún no tienes conversaciones» queda arriba, con vacío debajo. Es la línea base.
- [ ] **Step 2: Centrar solo los estados sin lista.** En el contenedor del cuerpo, calcula en el script `const sinLista = computed(() => !asistenteStore.conversaciones.length)` y aplica `min-h-[14rem] flex items-center justify-center` únicamente cuando `sinLista` es verdadero (carga, error y vacío); con la lista llena el contenedor conserva `min-h-[8rem]` y no es flex. El `EmptyState` ya trae `mx-auto`: no lo modifiques.
- [ ] **Step 3: Verificar el build.** Run: `cd avicola-frontend && npx vite build`. Expected: `✓ built`.
- [ ] **Step 4: Verificar los tres estados sin lista.** Expected: el vacío, el error de carga («No se pudo cargar el historial», con la API detenida) y «Cargando historial...» quedan centrados en vertical y horizontal dentro del modal, sin saltos bruscos al pasar de uno a otro.
- [ ] **Step 5: Verificar con la lista llena.** Con conversaciones, expected: la lista se ve exactamente igual que antes (alto máximo `55vh`, scroll propio, sin centrar).
- [ ] **Step 6: Verificar en móvil (390 px de ancho).** Expected: el bloque centrado cabe sin scroll horizontal y el botón «Cerrar» del pie sigue visible.

## Cierre

- [ ] **Step 1: Verificación final.** Run: `cd avicola-frontend && npx vite build` (Expected: `✓ built`) y la suite backend aislada (Expected: todo en verde, una prueba más que antes).
- [ ] **Step 2: Pint y estado.** Run: `docker compose exec -T api vendor/bin/pint tests/Feature/ConversacionAsistenteApiTest.php` y `git status --short` en ambos repos. Expected: Pint sin cambios pendientes; solo los archivos listados en las tres tareas.
- [ ] **Step 3: Sin commit.** Deja los cambios en el árbol de trabajo y avisa al usuario; los commits los pide él.
