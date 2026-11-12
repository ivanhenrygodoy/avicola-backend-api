# Plan de diagnóstico: latencia de la API

**Estado:** Fase 1 (evidencia) hecha el 2026-11-12. Fases 2 a 4 pendientes de aprobación.
**Síntoma:** las peticiones HTTP tardan entre 0,2 y 6 s, incluso `/up` (que no toca la base de datos), y las peticiones en paralelo se encolan.
**Regla del plan:** primero medir, después cambiar. Ningún cambio de configuración se aplica sin aprobación, y cada uno se mide antes y después con el mismo método.

## 1. Lo que ya está descartado

| Sospecha | Evidencia | Veredicto |
|---|---|---|
| Lógica de la aplicación lenta | Los servicios del asistente tardan ~15 ms | Descartada |
| Consultas SQL lentas | `select 1` tarda 3 ms; la primera conexión, 165 ms (una vez por petición) | Descartada |
| Contenedores con recursos limitados | `NanoCpus=0`, `CpuQuota=0`, `Memory=0` (sin límites); Docker Desktop tiene 12 CPU y 15,4 GiB; no existe `.wslconfig`; el contenedor usa 0,06 % de CPU | Descartada |
| Contenedor saturado durante la petición | CPU 0,09 % mientras el `curl` tarda 2 s | Descartada: la espera no es CPU |
| Red hacia la base de datos | Conexión 165 ms, consultas 3 ms | Descartada como causa principal |

## 2. Evidencia principal (medida)

Medido desde dentro del contenedor `avicola_api` (PHP 8.3 CLI, `php artisan serve`):

| Medición | Resultado |
|---|---|
| `require vendor/autoload.php` | **862 ms** |
| Arranque completo de Laravel | **4.704 ms** |
| Leer 119 archivos de `vendor/` (bind mount de Windows) | 628 ms (**5,28 ms/archivo**) |
| Leer 179 archivos del sistema de archivos del contenedor | 39 ms (0,22 ms/archivo) |
| 2.000 `stat()` en el bind mount | **1.838 ms** |
| 2.000 `stat()` en el sistema de archivos del contenedor | **1,1 ms** |
| `GET /up` secuencial ×8 | 2,8 / 0,25 / 1,9 / 0,25 / 0,41 / 0,25 / 2,0 / 0,19 s |

El código vive en un **bind mount** (`.:/var/www/html`) desde Windows. Cada archivo que PHP abre o comprueba cruza el límite Windows ↔ Linux, unas 1.700 veces más lento por `stat()`. El patrón de `/up` (rápido, rápido, lento…) coincide con la caché de metadatos que se enfría y se calienta.

Factor agravante: `opcache.enable_cli = Off`. `php artisan serve` lanza `php -S`, que usa el SAPI CLI, así que **OPcache está apagado para el servidor** y cada petición vuelve a leer, compilar y comprobar cientos de archivos a través del bind mount.

Otros factores presentes (efecto no medido aún): servidor de un solo proceso (`PHP_CLI_SERVER_WORKERS` comentado), `APP_DEBUG=true`, `LOG_LEVEL=debug` (`laravel.log` pesa 1,1 MB y también vive en el bind mount), caché, sesión y cola en `database`.

## 3. Hipótesis (una sola, a probar)

> **H1:** la latencia la causa la lectura del código y de `vendor/` a través del bind mount de Windows, agravada por OPcache apagado en el SAPI CLI. La cola entre peticiones paralelas la causa el proceso único de `php -S`.

## 4. Plan de verificación

Cada paso cambia **una** variable. Antes de cada paso se toma la línea base con el protocolo de medición (sección 5). Si un paso no mueve la medida, se revierte y se descarta esa hipótesis.

| Fase | Paso | Cambio (reversible) | Cómo confirma o descarta H1 | Riesgo |
|---|---|---|---|---|
| 2.1 | Activar OPcache en el servidor | `PHP_OPCACHE_ENABLE_CLI=1`, o un `.ini` con `opcache.enable_cli=1` y `opcache.validate_timestamps=1` | Si `/up` baja de forma notable, el coste es compilar/leer código en cada petición | Bajo: solo configuración de PHP |
| 2.2 | Más procesos en el servidor | Descomentar `PHP_CLI_SERVER_WORKERS=4` | Con 3 peticiones paralelas, el tiempo total deja de sumarse (hoy 9/11,6/15 s) | Bajo; la sesión en `database` ya es compatible |
| 3.1 | Quitar el bind mount del código | Mover `vendor/` a un volumen con nombre (`vendor_data:/var/www/html/vendor`) y dejar solo `app/`, `routes/`, etc. montados | Si el arranque pasa de ~4,7 s a décimas, H1 queda confirmada | Medio: cambia `docker-compose.yml` y exige `composer install` dentro del contenedor |
| 3.2 | Alternativa a 3.1 | Copiar el código dentro de la imagen para pruebas de latencia (sin volumen) | Aísla el efecto del mount sin tocar el flujo de desarrollo | Medio: los cambios exigen reconstruir la imagen |
| 3.3 | Código dentro de WSL2 | Mover el proyecto al sistema de archivos de WSL (`\\wsl$\...`) | Es el remedio habitual de Docker Desktop en Windows | Alto: cambia dónde vive el repositorio |
| 4.1 | Reducir el ruido de log | `LOG_LEVEL=info` y `APP_DEBUG=false` solo para la medición | Mide cuánto aporta el log en el bind mount | Bajo; se vuelve a `debug` después |
| 4.2 | Caché y sesión fuera de la BD | `CACHE_STORE=file`/`array` en medición | Cada petición con Sanctum y sesión hace consultas extra | Bajo |

Orden recomendado: **2.1 → 2.2 → 3.1**, porque son los de menor riesgo y los más probables. 3.3 solo si 3.1 no basta.

## 5. Protocolo de medición (el mismo antes y después de cada paso)

1. Reiniciar el contenedor: `docker compose restart api` (arranque en frío).
2. Esperar a que responda `/up`.
3. **Secuencial:** 10 peticiones a `http://localhost:8005/up`, registrando `time_total` y `time_starttransfer`. Anotar mínimo, mediana y máximo.
4. **Paralelo:** 3 peticiones simultáneas; anotar el tiempo de cada una.
5. **Arranque de Laravel:** `php artisan --version` dentro del contenedor, 3 veces.
6. **Endpoint real:** una petición autenticada a `GET /api/v1/dashboard/resumen?dias=7` desde el navegador (pestaña Red), porque solo ahí intervienen Sanctum y la base de datos.
7. Registrar CPU y memoria con `docker stats --no-stream` durante las pruebas.

Comandos útiles:

```bash
# Límites de recursos del contenedor (0 = sin límite)
docker inspect avicola_api --format 'cpus={{.HostConfig.NanoCpus}} quota={{.HostConfig.CpuQuota}} mem={{.HostConfig.Memory}}'
# Capacidad que Docker Desktop da a los contenedores
docker info --format 'CPUs={{.NCPU}} Mem={{.MemTotal}}'
# Tiempo de /up
curl -s -o /dev/null -w "total=%{time_total}s ttfb=%{time_starttransfer}s\n" http://localhost:8005/up
# Coste de abrir archivos en el bind mount frente al sistema del contenedor
#   (script temporal bajo storage/, ejecutado con `php`, y borrado después)
```

## 6. Criterio de éxito

- `GET /up` con mediana por debajo de 100 ms y máximo por debajo de 500 ms.
- Arranque de Laravel por debajo de 500 ms.
- 3 peticiones paralelas sin que el tiempo total se acerque a la suma de los individuales.
- `dashboard/resumen` autenticado por debajo de 500 ms.

## 7. Qué NO se toca

- Datos de la base de datos (no se ejecutan migraciones ni se escribe en ella).
- El código de la aplicación (el problema medido no está ahí).
- La configuración de producción: este plan es solo para el entorno de desarrollo en Docker Desktop.

## 8. Registro de resultados

| Fecha | Paso | Antes | Después | Conclusión |
|---|---|---|---|---|
| 2026-11-12 | Fase 1: evidencia | — | arranque 4,7 s, `stat()` 1.700× más lento en el bind mount | H1 plausible, pendiente de confirmar con 2.1 y 3.1 |
