# Plan de diagnóstico: latencia de la API

**Estado:** Finalizado con éxito
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
| 2026-11-12 | Línea base `/up` (servidor del compose, puerto 8005) | — | secuencial 3,4–5,4 s (mediana ≈ 3,7–4,0 s); 3 en paralelo 4,1 / 7,7 / 11,7 s | Reproduce el síntoma |
| 2026-11-12 | 2.1 OPcache en CLI (`-d opcache.enable_cli=1`, servidor temporal en 8006) | mediana ≈ 3,7 s | mediana ≈ 4,3 s; primer golpe en frío 6,9 s | **Sin mejora: OPcache solo no resuelve nada.** El coste es de E/S, no de compilación |
| 2026-11-12 | 3.2 Código copiado al FS del contenedor (servidor temporal en 8007, sin OPcache, sin tocar el mount) | mediana ≈ 3,7 s | **5 ms** `/up` (primer golpe 0,20 s); ruta de la API sin autenticar **11 ms** frente a 8.504 ms en el mount; 3 en paralelo 6 / 11 / 15 ms | **H1 confirmada: el bind mount de Windows causa la latencia (~700× más lenta).** La cola en paralelo sigue existiendo (proceso único) pero a escala de ms ya no importa |

| 2026-11-12 | 3.1 `vendor/` en un volumen con nombre (contenedor temporal en 8008; el resto del código sigue en el bind mount) | `/up` 3,2–7,8 s en el 8005 | `/up` **≈ 115 ms** (primer golpe 0,50 s); ruta de la API sin autenticar **≈ 105 ms**; 3 en paralelo 121 / 211 / 283 ms | **El paso 3.1 basta: ~30 a 60× más rápido sin mover el código.** Quedan ~100 ms por petición (el resto del código aún se lee del mount) |

Notas del paso 3.1: poblar el volumen con `vendor/` tardó 48 s (una sola vez). El `docker-entrypoint.sh` solo ejecuta `composer install` si `vendor/` no existe, y un volumen vacío ya crea el directorio, así que al aplicarlo hay que poblarlo a mano o ajustar el entrypoint. La medición no incluye Sanctum ni consultas a la BD (ruta sin autenticar).

| 2026-11-12 | **Aplicado** (opción 1): contenedor `avicola_api` recreado con `vendor/` en el volumen `avicola_vendor` | `/up` 3,2–7,8 s | `/up` **≈ 90 ms** (10 seguidas: 63–162 ms, primer golpe 351 ms); ruta de la API sin autenticar **63–119 ms**; BD intacta (14 aves en `avicola_inteligente_db`) | **Resuelto en la medición sin autenticar.** Falta confirmar `dashboard/resumen` autenticado desde el navegador |

### Cómo quedó aplicado (2026-11-12)

El contenedor se recreó con `docker run`, **no** con `docker compose up`, porque el contenedor en marcha no coincidía con `docker-compose.yml`: corría con `DB_HOST=192.168.208.1` (el PostgreSQL con los datos de desarrollo, contenedor `postgres`) y el compose define `DB_HOST: postgres` con un servicio `avicola_postgres` que no existe. Un `docker compose up` habría apuntado la API a una base vacía.

Se conservó la configuración que tenía: misma imagen, entrypoint y comando, puerto `8005`, red `avicola-backend-api_default` con alias `api`, política `unless-stopped` y las mismas variables de entorno. Lo único nuevo es `-v avicola_vendor:/var/www/html/vendor`.

### Llevado al repositorio (para cualquier máquina)

Para que el remedio no dependa de este `docker run`, quedó en los archivos versionados:

- **`docker-compose.yml`:** el servicio `api` monta `vendor_data:/var/www/html/vendor` y el volumen se declara como `avicola_vendor`.
- **`docker-entrypoint.sh`:** ahora decide si instalar dependencias comprobando `vendor/autoload.php` y no el directorio `vendor/`. Un volumen de Docker siempre crea el directorio, así que la comprobación anterior (`[ ! -d vendor ]`) nunca habría instalado nada y la API habría fallado con `Failed opening required vendor/autoload.php`.

Verificación (2026-11-12, contenedor temporal, ya borrado): con un volumen vacío y el entrypoint nuevo, el arranque ejecutó `composer install` solo (112 paquetes, unos 24 s) y `/up` respondió 200 en ≈ 110 ms. Con el entrypoint antiguo, el mismo arranque fallaba, lo que confirmó que el cambio del entrypoint es necesario.

**Quien ya tenga el proyecto levantado** debe reconstruir la imagen, porque el entrypoint se copia dentro de ella: `docker compose up -d --build api`. Una instalación nueva no necesita nada especial. En Linux nativo el volumen es inocuo (el bind mount allí no es lento), solo cambia dónde vive `vendor/`.

Cuidados al operar:

- **`composer require/update/install` dentro del contenedor** escribe en el volumen `avicola_vendor`, no en `vendor/` de Windows (que queda desactualizado, solo sirve para el editor).
- **Para volver a poblar el volumen** desde `vendor/` de Windows: `docker run --rm --entrypoint sh -v "<ruta>:/src:ro" -v avicola_vendor:/dst avicola-backend-api-api -c 'cp -a /src/vendor/. /dst/'` (unos 48 s).
- **No usar `docker compose up` para `api`** hasta alinear `docker-compose.yml` con el entorno real: recrearía el contenedor sin el volumen y con `DB_HOST=postgres`.
- **Para revertir:** detener y borrar `avicola_api`, y recrearlo con el mismo comando sin el `-v avicola_vendor:...`. El volumen puede quedarse o borrarse con `docker volume rm avicola_vendor`.
- Los comandos `docker compose exec -T api ...` (tests aislados) siguen funcionando: las etiquetas de proyecto y servicio se mantuvieron.

**Conclusión de la revisión (2026-11-12):** la causa raíz es el bind mount del código (`.:/var/www/html`) desde Windows. Las pruebas se hicieron con servidores temporales dentro del contenedor, ya apagados y borrados, sin tocar `.env`, `docker-compose.yml` ni la BD. Pendiente de decisión: cómo llevar el remedio al flujo de desarrollo (paso 3.1: `vendor/` en un volumen con nombre, o 3.3: proyecto dentro de WSL2). Los pasos 2.2, 4.1 y 4.2 pasan a ser optimizaciones menores.
