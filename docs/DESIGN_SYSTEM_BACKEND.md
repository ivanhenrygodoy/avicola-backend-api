# Estándares de Arquitectura Backend (avicola-backend-api)

Documentación de buenas prácticas y convenciones del backend en Laravel 11.

## Convenciones de Base de Datos
- **Catálogos:** Prefijo `ctl_` (ej. `ctl_tipos_alimento`, `ctl_categorias_gastos`).
- **Mantenimiento / Negocio:** Prefijo `mnt_` (`mnt_usuarios`, `mnt_granjas`, `mnt_casitas`, `mnt_divisiones`, etc.).
- **Clave Primaria:** `id`
- **Claves Foráneas:** `id_<entidad>` (`id_granja`, `id_casita`, `id_division`, `id_ave`).
- **Integridad:** `restrictOnDelete()` en relaciones críticas.

## Principios SOLID
- **SRP:** Thin Controllers + FormRequests para validación + Servicios para flujos transaccionales.
- **OCP:** Extensión sin modificar código base mediante eventos e interfaces.
- **LSP / ISP:** Requests y modelos especializados por acción (`StoreCasitaRequest`, `UpdateCasitaRequest`).
- **DIP:** Inyección de dependencias en constructores y controladores.

## Seguridad y Pruebas
- Autenticación Sanctum, Rate limiting (5 intentos/min).
- Pruebas automatizadas en `tests/Feature/` con 100% de éxito.

