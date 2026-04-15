# Arquitectura de testkit

## 1) Propósito

`testkit` separa plataforma de testing y proyecto integrador.

La plataforma centraliza ejecución, bootstrap y reporting. El proyecto conserva tests, seeds y lógica de dominio.

## 2) Estructura interna

```text
testkit/
├─ bin/
├─ compose*.yaml
├─ core/php/
│  ├─ common/        # env, paths, utilidades base
│  ├─ config/        # lectura de parámetros y contrato de suites
│  ├─ discovery/     # resolución de tests, tags y filtros
│  ├─ execution/     # procesos, pool y resultados
│  ├─ reporting/     # reportes JSON, resumen, historial
│  ├─ coverage/      # merge y diagnóstico de coverage
│  ├─ seeding/       # baseline layered/snapshot y manifest
│  ├─ store/         # adapters y operaciones de store
│  └─ suites/        # suites por tecnología y suites técnicas
├─ runners/
├─ scripts/
├─ templates/
└─ utils/
```

## 3) Frontera con el proyecto

### 3.1) Lo que pertenece a testkit

`testkit` es dueño de:

- runners y selección de targets
- discovery compartido
- bootstrap estructural
- naming de DB/store para workers y baseline
- adapters de store y restricciones operativas
- formato de reportes del framework

### 3.2) Lo que pertenece al proyecto

El proyecto es dueño de:

- tests de dominio
- `test/_support`
- SQL de `schema`, `base`, `migrations` y `validations`
- servicios y dependencias propias del proyecto
- criterios funcionales que definen éxito o falla del negocio

La frontera importante es esta: `testkit` ejecuta y prepara infraestructura; el proyecto define qué debe validarse.

## 4) Lifecycle de una corrida

Una corrida típica atraviesa estas capas:

1. `bin/testkit` o `bin/testkit.ps1` resuelven repo, env y compose.
2. `runTest.php` selecciona target y suites.
3. cada suite arma su configuración, discovery y ejecución.
4. si la suite necesita store real, `ContractWorldBootstrap` aplica la política de bootstrap.
5. `SeedPipeline` materializa el baseline (`layered` o `snapshot`).
6. `reporting` escribe artefactos bajo el repo del proyecto.

## 5) Lifecycle de store

`testkit` controla el lifecycle estructural del store:

- provision
- reset
- materialización de baseline
- clone por worker cuando aplica
- reportes técnicos del bootstrap

El proyecto no debe redefinir ese lifecycle desde helpers de dominio.

Lo que sí debe hacer el proyecto:

- proveer el SQL estructural
- decidir qué migraciones existen
- construir escenarios funcionales después del baseline

## 6) Artefactos y ownership

Los artefactos operativos del framework viven dentro del repo del proyecto, principalmente en `.testkit/`.

Eso mantiene dos propiedades:

- el estado operacional queda junto al proyecto auditado
- `testkit` no se apropia de resultados que describen a otro repositorio

Coverage sigue bajo `test/coverage/` porque es una salida consumida directamente por el proyecto.

## 7) Alcance real por motor

### MySQL

Ruta principal cerrada:

- provision
- reset
- restore snapshot
- clone database
- suites que dependen de ese lifecycle, incluido `migration-contract`

### PostgreSQL

Soporte parcial de infraestructura:

- puede existir como store de pruebas
- snapshot restore y clone no forman parte del contrato cerrado de esta fase

### Redis

`testkit` puede levantar el servicio en compose, pero no tiene lifecycle estructural equivalente al de DB SQL dentro del core PHP.

## 8) Decisiones de diseño vigentes

- La plataforma es opinionated: prefiere contrato explícito antes que inferencia flexible.
- El bootstrap estructural vive en `testkit`, no en `test/_support`.
- `migration-contract` es una suite técnica de bootstrap y migración; no una suite funcional.
- Heurísticas de reporting sirven para triage, no para reemplazar diagnóstico real.
