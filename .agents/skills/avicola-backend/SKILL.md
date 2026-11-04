---
name: avicola-backend
description: >-
  Estándares de arquitectura, principios SOLID, convenciones estrictas de base de datos
  (ctl_, mnt_, id_<entidad>), capas de servicio, seguridad Sanctum y pruebas
  automatizadas para avicola-backend-api (Laravel 11 + PHP 8.3 + PostgreSQL 16).
---

# Estándares de Arquitectura Backend (avicola-backend-api)

Guía oficial para el desarrollo robusto, desacoplado y seguro del backend de la Granja Avícola Inteligente basado en Laravel 11.

---

## 1. Convenciones Estrictas de Base de Datos

| Tipo de Tabla | Prefijo Obligatorio | Ejemplos | Propósito |
| :--- | :--- | :--- | :--- |
| **Catálogos** | `ctl_` | `ctl_tipos_alimento`, `ctl_categorias_gastos` | Tablas maestras/referenciales con pocos cambios. |
| **Mantenimiento / Negocio** | `mnt_` | `mnt_usuarios`, `mnt_granjas`, `mnt_casitas`, `mnt_divisiones`, `mnt_aves`, `mnt_movimientos_aves`, `mnt_registros_alimentacion`, `mnt_registros_huevos`, `mnt_registros_peso`, `mnt_observaciones_aves`, `mnt_gastos` | Tablas transaccionales y operativas del negocio. |

### Convenciones de Claves y Relaciones
- **Clave Primaria:** Siempre `id` (`$table->id()`).
- **Claves Foráneas:** Siempre con el formato estricto `id_<entidad>`:
  - `id_granja` (apunta a `mnt_granjas.id`)
  - `id_casita` (apunta a `mnt_casitas.id`)
  - `id_division` (apunta a `mnt_divisiones.id`)
  - `id_ave` (apunta a `mnt_aves.id`)
  - `id_categoria_gasto` (apunta a `ctl_categorias_gastos.id`)
- **Integridad Referencial:** Aplicar `restrictOnDelete()` para proteger la coherencia de datos (ej. una casita no puede eliminarse si contiene corrales; un corral no puede eliminarse si contiene aves).

---

## 2. Principios SOLID Aplicados al Backend

1. **S - Single Responsibility Principle (SRP):**
   - **Controladores Delgados (Thin Controllers):** Solo reciben la petición, delegan al FormRequest/Servicio y retornan JSON estandarizado.
   - **FormRequests:** Responsables únicos de la autorización y validación de reglas de entrada (`rules()`).
   - **Servicios de Negocio:** Encapsulan flujos complejos, cálculos y transacciones de base de datos (ej. `TrasladarAveService`).
   - **Modelos Eloquent:** Representan las entidades, relaciones y scopes de consulta.

2. **O - Open/Closed Principle (OCP):**
   - Extensiones mediante Interfaces de Servicio y eventos de dominio de Laravel sin modificar el código core existente.

3. **L - Liskov Substitution Principle (LSP):**
   - Implementaciones de servicios sustituibles sin alterar el contrato esperado por los controladores.

4. **I - Interface Segregation Principle (ISP):**
   - FormRequests especializados por acción: `StoreCasitaRequest` y `UpdateCasitaRequest` independientes en lugar de un único validador monolítico.

5. **D - Dependency Inversion Principle (DIP):**
   - Inyección de dependencias en controladores a través del contenedor de servicios de Laravel en constructores o métodos de acción.

---

## 3. Formato Estándar de Respuestas JSON

Todas las respuestas de la API (`/api/v1/`) deben seguir una estructura consistente:

### Éxito (Recurso Individual / Acción)
```json
{
  "success": true,
  "message": "Casita creada exitosamente.",
  "data": { "id": 1, "nombre": "Casita 1", ... }
}
```

### Éxito (Colección Paginada con Estadísticas)
```json
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 5,
    "total": 14,
    "from": 1,
    "to": 5
  },
  "stats": {
    "total_casitas": 3,
    "total_divisiones": 5,
    "divisiones_activas": 5
  }
}
```

### Error de Validación (422) o Lógica de Negocio
```json
{
  "success": false,
  "message": "No se puede eliminar la casita porque tiene divisiones asociadas."
}
```

---

## 4. Seguridad y Rendimiento

1. **Autenticación:** Tokens Bearer gestionados por **Laravel Sanctum**.
2. **Rate Limiting:** Máximo 5 intentos por minuto en endpoints de autenticación (`LoginRequest`).
3. **Optimización de Consultas:** Uso obligatorio de Eager Loading (`with('divisiones')`) para eliminar problemas de consultas N+1.
4. **Transacciones:** Toda operación que modifique múltiples tablas o realice validaciones biológicas cruzadas debe ejecutarse dentro de `DB::transaction(...)`.

---

## 5. Pruebas Automatizadas Obligatorias

Todo módulo del sistema debe contar con su suite de pruebas automatizadas en `tests/Feature/`:
- `AuthTest.php`: Login exitoso, credenciales inválidas, rate limiting, me, logout.
- `InstalacionesTest.php`: CRUD de casitas, toggle activa, creación de corrales, bloqueo de eliminación de casitas con corrales asociados.
- Ejecución periódica: `php artisan test` con verificación del 100% de aserciones pasando.

---

## 6. Configuración Docker y Orquestación (SOLID & Desacoplado)

- **`Dockerfile`:** Construye la imagen de la API (`php:8.3-cli-alpine`) con soporte completo para PostgreSQL (`pdo_pgsql`, `pgsql`, `postgresql-client`), Composer 2 y optimizaciones del sistema.
- **`docker-compose.yml`:** Orquesta dos servicios desacoplados en red interna (`avicola_network`):
  1. `api`: Contenedor Laravel expuesto en el puerto `8005:8005`, dependiente del estado saludable del motor de base de datos.
  2. `postgres`: Contenedor PostgreSQL 16 oficial con soporte nativo de vectores (`pgvector/pgvector:pg16`), volumen persistente `postgres_data` y verificación de salud (`pg_isready`).
- **`docker-entrypoint.sh`:** Verifica permisos de almacenamiento, instalación de dependencias y asegura la disponibilidad de conexión a PostgreSQL antes de iniciar el servicio HTTP.

