# Etapa 9: Pruebas Integrales, Respaldos y Cierre Operativo Implementation Plan

**Estado:** Finalizado con éxito

> **Enfoque de Desarrollo:** Spec-Driven Development (SDD) con TDD en Backend y Verificación E2E.
> **Referencia:** `README.md` §Etapa 9, `.agents/skills/avicola-{backend,frontend,design-system}`, arquitectura de `docs/aviacore-*.md`.

**Goal:** Culminar el desarrollo de la Granja Avícola Inteligente garantizando la resiliencia operativa y la estabilidad en producción local mediante: (1) un sistema automatizado de respaldos y restauración de PostgreSQL con soporte de `pgvector`, (2) una prueba integral E2E que valide el ciclo de vida completo de la granja, y (3) un manual exhaustivo de despliegue y puesta en marcha en frío.

**Architecture:**
- **Sistema de Respaldos (PostgreSQL & pgvector):**
  - Comando Artisan `avicola:respaldo-bd {--rotacion=7}`: Ejecuta `pg_dump` con compresión `.sql.gz`, almacena en `storage/app/backups/`, verifica integridad y rota copias antiguas.
  - Comando Artisan `avicola:restaurar-bd {archivo?}`: Permite restaurar de forma segura un respaldo previo.
  - Programación en el Scheduler de Laravel (`routes/console.php`) para ejecución periódica diaria.
- **Suite de Pruebas Integrales E2E (`E2EFlujoGranjaTest`):**
  - Prueba de ciclo completo: Autenticación -> Instalaciones -> Registro de Aves con regla biológica -> Postura y Alimento -> Finanzas -> Dashboard -> Consulta a AviaCore.
- **Manual de Puesta en Marcha y Despliegue (`docs/manual-despliegue-puesta-en-marcha.md`):**
  - Estructurado en Parte 1 (General/Operativa) y Parte 2 (Técnica/Comandos).

---

## Tareas de Implementación (SDD / TDD)

### Task 1: Sistema de Respaldos y Rotación de Base de Datos
- Create `app/Console/Commands/RespaldoBaseDatosCommand.php` (`avicola:respaldo-bd`)
- Create `app/Console/Commands/RestaurarBaseDatosCommand.php` (`avicola:restaurar-bd`)
- Schedule en `routes/console.php` (ejecución diaria a medianoche)
- Test `tests/Feature/RespaldoTest.php`

### Task 2: Prueba Integral de Ciclo de Vida E2E
- Create `tests/Feature/E2EFlujoGranjaTest.php` (Ciclo de vida completo del sistema integrando módulos y AviaCore)

### Task 3: Manual de Despliegue y Puesta en Marcha
- Create `docs/manual-despliegue-puesta-en-marcha.md` (Parte General + Parte Técnica)
- Update `README.md` del proyecto y READMEs de cada submódulo marcando el proyecto al 100% completado.

