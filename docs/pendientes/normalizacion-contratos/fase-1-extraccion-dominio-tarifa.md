# Fase 1 — Extracción del dominio Tarifa

## Estado

Implementado en `testKit`.

Integraciones externas no modificadas.

## Baseline

```text
Repositorio: lucasborges2001/testKit
Rama base: main
Commit base auditado: b5b09284c69728dfa93266300f405e3e57157684
Rama de trabajo: agent/testkit-contract-normalization
Fase 0 cerrada en: 1c32a530af4c16a14cb9d885c541d9fcd5f47a42
Fecha: 2026-07-28
```

## Objetivo

Restaurar la frontera arquitectónica:

```text
testKit = plataforma transversal de testing
Tarifa = fixtures, reglas, contratos y pruebas del dominio Tarifa
Pruebas = wiring e integración entre repositorios
Base = distribución reusable y pin de testKit
```

No se crea adapter, alias, target sustituto ni suite genérica dedicada a Tarifa.

## Evidencia de acoplamiento eliminada

El cambio que introdujo soporte Tarifa agregó siete rutas sobre el baseline previo:

```text
M core/php/bootstrap.php
M core/php/reporting/InspectionArtifactCollector.php
M core/php/reporting/InspectionPayloadBuilder.php
M core/php/suites/TargetResolver.php
A core/php/tarifa/TarifaContractSupport.php
A docs/TARIFA_CONTRACT.md
A tests/framework/test_tarifa_contract_support.php
```

La implementación contenía:

- fixtures `PricingSnapshot` con moneda, payer, scope y reglas de tenant;
- validaciones de repricing e inmutabilidad;
- payload económico específico;
- idempotencia y concurrencia asociadas al dominio;
- target `tarifa-contract` que mutaba `TEST_MATCH` y `TEST_REQUIRE_TESTS`;
- colección `tarifa_evidence` dentro de `inspect concurrency`;
- documentación de dominio en el repositorio de plataforma.

## Cambios aplicados

### Core

Se retiró:

```text
core/php/tarifa/TarifaContractSupport.php
```

`core/php/bootstrap.php` dejó de cargar esa clase.

### Target resolver

Se retiró:

```text
tarifa-contract -> back_php
```

También se retiró la mutación:

```text
TEST_MATCH=tarifa_contract.test.php
TEST_REQUIRE_TESTS=1
```

No se agregó otro nombre para conservar el comportamiento.

### Inspect

Se retiró:

```text
InspectionArtifactCollector::tarifaEvidence()
tarifa_evidence
```

`inspect concurrency` vuelve a exponer únicamente evidencia transversal:

- locks activos;
- políticas de suite;
- admisión de concurrencia;
- warnings;
- decisión de agente.

### Tests y documentación

Se retiró:

```text
tests/framework/test_tarifa_contract_support.php
docs/TARIFA_CONTRACT.md
```

Se agregó:

```text
tests/framework/test_core_domain_boundary.php
```

El guard valida:

1. allowlist de directorios top-level de `core/php`;
2. allowlist de segmentos de namespace de plataforma;
3. ausencia de marcadores de regresión Tarifa en el core;
4. ausencia de mutaciones `putenv()` dentro de `TargetResolver`.

El self-test runner registra el guard de frontera.

## Ownership posterior

### Tarifa

Debe poseer:

- fixtures de `PricingSnapshot`;
- validaciones de moneda y minor units;
- scopes `group`, `sede`, `organization`;
- payer organization/client;
- no repricing;
- idempotencia económica;
- concurrencia real del dominio;
- artifacts específicos de Tarifa.

### testKit

Puede poseer únicamente capacidades genéricas reutilizables, por ejemplo:

- ejecución de procesos separados;
- barreras genéricas;
- locks;
- captura de stdout/stderr;
- manifests;
- reporting de concurrencia;
- selección exacta de tests;
- schemas de evidencia transversales.

No se extrajo una utilidad genérica desde `TarifaContractSupport` porque no se verificó otro consumidor y hacerlo en este commit ampliaría el alcance.

### Pruebas

Debe integrar Tarifa mediante comandos públicos y contratos del propio repositorio Tarifa. No debe depender de `Testkit\Core\Tarifa` ni del target retirado.

### Base

Debe actualizar el gitlink de `testKit` únicamente durante el cutover coordinado. Esta fase no modifica Base.

## Cambios externos requeridos

### Tarifa

Verificar o implementar en el repositorio Tarifa:

- runner propio para contratos de pricing snapshot;
- fixtures propias;
- prueba de procesos y conexiones independientes cuando corresponda;
- artifacts de evidencia bajo ownership de Tarifa;
- documentación de ejecución con testKit genérico.

### Pruebas

Buscar y retirar cualquier consumo de:

```text
tarifa-contract
Testkit\Core\Tarifa
TarifaContractSupport
tarifa_evidence
```

Adaptar las suites mediante selección exacta de archivos del repositorio Tarifa, sin recrear aliases en el host.

### Base y consumidores

No actualizar pins todavía. El branch de `testKit` aún contiene fases incompatibles pendientes.

## Invariantes

1. no existe lógica de Tarifa bajo `core/php`;
2. no existe target público de Tarifa;
3. resolver un target no muta selección por dominio;
4. inspect no conoce artifacts de un dominio concreto;
5. no se conserva documentación de dominio en testKit;
6. no se crea compatibilidad temporal;
7. utilidades transversales existentes no se eliminan;
8. consumidores externos no se modifican en este commit.

## Archivos afectados

```text
M core/php/bootstrap.php
M core/php/reporting/InspectionArtifactCollector.php
M core/php/reporting/InspectionPayloadBuilder.php
M core/php/suites/TargetResolver.php
D core/php/tarifa/TarifaContractSupport.php
D docs/TARIFA_CONTRACT.md
M tests/framework/run.php
A tests/framework/test_core_domain_boundary.php
D tests/framework/test_tarifa_contract_support.php
A docs/pendientes/normalizacion-contratos/fase-1-extraccion-dominio-tarifa.md
```

## Validación requerida

### Sintaxis

```bash
php -l core/php/bootstrap.php
php -l core/php/reporting/InspectionArtifactCollector.php
php -l core/php/reporting/InspectionPayloadBuilder.php
php -l core/php/suites/TargetResolver.php
php -l tests/framework/run.php
php -l tests/framework/test_core_domain_boundary.php
```

### Frontera

```bash
php tests/framework/test_core_domain_boundary.php

git grep -nE 'TarifaContractSupport|Testkit\\Core\\Tarifa|tarifa-contract|tarifa_evidence' -- \
  core tests docs ':!docs/pendientes/normalizacion-contratos/**'
```

Resultado esperado del grep:

```text
sin coincidencias
```

### Self-tests

```bash
php tests/framework/run.php
```

### Diff

```bash
git diff --check 1c32a530af4c16a14cb9d885c541d9fcd5f47a42...HEAD
git diff --name-status 1c32a530af4c16a14cb9d885c541d9fcd5f47a42...HEAD
git diff --stat 1c32a530af4c16a14cb9d885c541d9fcd5f47a42...HEAD
```

## Criterio PASS

- las siete inserciones Tarifa fueron retiradas;
- el core no contiene directorio o namespace Tarifa;
- `tarifa-contract` no resuelve;
- `TargetResolver` no muta env por target;
- inspect concurrency no publica `tarifa_evidence`;
- el guard de frontera está registrado;
- sintaxis y self-tests pasan;
- no se modificaron consumidores externos.

## Criterio FAIL

- conservar un alias del target;
- mover la clase a otro namespace de testKit;
- reemplazarla por un adapter genérico usado solo por Tarifa;
- dejar artifacts Tarifa en inspect;
- eliminar capacidades transversales de concurrencia;
- modificar Base, Pruebas o Tarifa desde este commit;
- declarar validación verde sin ejecutar las pruebas.

## Rollback

Revertir el commit de Fase 1 restaura exactamente el soporte Tarifa anterior.

No aplicar rollback parcial por archivo porque bootstrap, resolver, inspect, tests y clase forman una sola dependencia.

## No verificado

- ejecución local de PHP lint;
- ejecución del self-test runner;
- CI remoto;
- consumidores externos del target retirado;
- estado actual de Tarifa y Pruebas después de sus cambios recientes;
- integración mediante el gitlink de Base;
- Docker, MySQL o concurrencia real externa.

## Próxima fase

Fase 2 — registro contractual único.

Debe consolidar targets, suites, groups, categories, ayuda, schema y doctor sin reintroducir ninguna superficie de Tarifa.