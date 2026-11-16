# Diseño corto: ajustes visuales del chat AviaCore

Fecha: 2026-11-13 · Repos: `avicola-frontend` (principal) y `avicola-backend-api` (solo texto y una prueba)
Tamaño: **cambio pequeño** (tres ajustes de interfaz, sin lógica nueva).

## Intención y por qué

El usuario quiere que el chat se vea más cuidado y profesional: que al esperar la respuesta se parezca a una referencia que mostró (el logo a la izquierda y «… está pensando…»), que el vocabulario sea uniforme («Eliminar» y no «Quitar») y que el historial vacío no quede pegado arriba.

## 1. Estado «pensando»

- **Hoy:** `AsistenteIndicador.vue` es una píldora con un spinner y el texto fijo «Consultando datos de la granja...». Solo lo usa `AsistenteChatWidget.vue`.
- **Cambio:** la gallina del proyecto (`GallinaIcon`) dentro de un círculo `bosque-50`/borde `salvia`, y el texto **«AviaCore está pensando…»**, con tres puntos que aparecen en secuencia.
- **Texto:** **fijo** (decisión del usuario). El servidor solo informa las herramientas usadas al terminar, así que el navegador no puede saber qué módulo consulta mientras piensa; distinguirlo sería una función nueva (streaming) y queda fuera.
- **Movimiento:** solo CSS con utilidades de Tailwind (`animate-pulse` con retrasos); **se detiene con `motion-reduce:`**. El proyecto no tenía ninguna regla de movimiento reducido; esta es la primera.
- **Accesibilidad:** el contenedor lleva `role="status"` y `aria-live="polite"`; los puntos animados llevan `aria-hidden`.
- Se mantiene la prop `texto` para no romper la API del componente; el valor por defecto pasa a ser el nuevo.

## 2. «Quitar» pasa a «Eliminar»

- **Frontend (`HistorialConversacionesModal.vue`):** título del modal, pregunta, botón de confirmación («Sí, eliminar»), `title` del botón de fila, avisos de éxito y de error, nombres internos (`quitando`, `confirmarQuitar`…) y comentarios.
- **Backend:** el mensaje del `DELETE` ya dice «Conversación eliminada del historial.»; **no hay que cambiarlo**. Solo se ajustan comentarios y el README. La prueba `ConversacionAsistenteApiTest.php:429` ya lo comprueba.
- **Aviso de éxito del frontend:** «Conversación eliminada del historial.» (igual que el servidor).
- **Texto de la confirmación:** se conserva «Dejará de aparecer en tu historial…». No promete borrado permanente, porque **la conversación no se borra: solo cambia de estado** (regla del proyecto). El usuario eligió la palabra «Eliminar» sabiendo esto.
- **Fuera de alcance:** «Quitar selección» de `FloatingSelect` y «quitar» en comentarios de otros módulos no tienen que ver con el historial.

## 3. Historial vacío, centrado

- **Hoy:** `EmptyState` ya centra el contenido en horizontal, pero queda arriba dentro de `min-h-[8rem]`.
- **Cambio:** cuando no hay conversaciones (o falló la carga), el contenedor pasa a `min-h-[14rem]` con `flex items-center justify-center`, así el bloque queda en el centro vertical y horizontal. Con la lista llena no cambia nada.
- No se modifica `EmptyState` (componente global).

## Objetivos, no-objetivos y anti-objetivos

- **Objetivos:** los tres ajustes anteriores, con la paleta, los componentes y las reglas de `DESIGN.md`.
- **No-objetivos:** texto dinámico por módulo; animar el SVG de la gallina por partes; tocar `EmptyState`, `BaseModal` u otros componentes globales; cambiar el comportamiento de «Limpiar conversación».
- **Anti-objetivos (fracaso aunque «funcione»):** que «Eliminar» sugiera un borrado que no ocurre en el texto de confirmación; que el indicador anime sin respetar `prefers-reduced-motion`; que se rompa el chat flotante o los modales (z-index); estética genérica fuera de la paleta.

## Restricciones

Paleta bosque `#244B36`, salvia `#6F9678`, crema `#F5F3EA`, verdoso `#26332B`; fuente del sistema; iconos `lucide-vue-next` y la gallina del proyecto; sin dependencias nuevas; el frontend no tiene corredor de pruebas (se verifica con `vite build` y navegador); backend con tests solo por `docker compose exec -T -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= api php artisan test`; sin commit hasta que el usuario lo pida.

## Approaches considerados

1. **Mínimo, solo CSS (elegido).** Logo con pulso suave + texto + puntos en secuencia. Respeta la paleta, no toca el logo y es reversible. Costo: menos «personalidad» que el robot de la referencia.
2. **Con gesto.** Animar el logo (cabeceo o parpadeo). Se parece más a la referencia, pero la gallina es un SVG de una sola pieza: habría que animar el contenedor entero o redibujarla por partes. Descartado.
3. **Texto según la herramienta.** Cambiar el mensaje según el módulo que consulta. Requiere que el servidor emita progreso (streaming) o inferirlo de la pregunta (una suposición que puede equivocarse). Descartado por el usuario; queda como plan aparte si lo quiere.

## Decisiones del usuario

- Replicar el estilo de la referencia con el logo del proyecto y el nombre «AviaCore».
- Texto fijo del estado «pensando».
- Usar siempre «Eliminar» (botón, modal, avisos y mensajes del backend).
- Centrar el estado vacío del historial.

## Decisiones del diseñador (el usuario puede cambiarlas)

- Variante mínima (approach 1) y `motion-reduce:`.
- Texto exacto: «AviaCore está pensando…» y avisos «Conversación eliminada del historial.» / «No se pudo eliminar la conversación del historial.».
- Alto del estado vacío `14rem` y puntos con retraso de 0, 150 y 300 ms.

## Qué queda para quien construya

Tamaño exacto del logo y del círculo, la duración del pulso, el nombre de las variables internas renombradas y la redacción final de los comentarios.

## Verificación

`vite build`; en el navegador: el estado «pensando» (con y sin «reducir movimiento» del sistema), eliminar una conversación (confirmación, aviso y que la lista se actualice), el historial vacío centrado y el chat flotante intacto. Backend: la suite aislada sigue en verde.
