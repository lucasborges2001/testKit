# Troubleshooting operativo

## 1) Qué responde esta guía

Usar esta guía cuando ya tenés un síntoma y necesitás una ruta de diagnóstico concreta.

No usarla como quick start ni como contrato general.
Para eso, leer:

- [`USO.md`](USO.md)
- [`CONTRATO.md`](CONTRATO.md)

## 2) Comandos de referencia

### Linux/macOS

```bash
./bin/testkit doctor --compact
./bin/testkit doctor --full
./bin/testkit doctor --dump --full
./bin/testkit doctor --full migration-contract
./bin/testkit inspect latest
./bin/testkit inspect failure
./bin/testkit inspect seed-state
./bin/testkit inspect concurrency
```

### PowerShell

```powershell
.\bin\testkit.ps1 doctor --compact
.\bin\testkit.ps1 doctor --full
.\bin\testkit.ps1 doctor --dump --full
.\bin\testkit.ps1 doctor --full migration-contract
.\bin\testkit.ps1 inspect latest
.\bin\testkit.ps1 inspect failure
.\bin\testkit.ps1 inspect seed-state
.\bin\testkit.ps1 inspect concurrency
```

## 3) Cómo leer `doctor`

`doctor` tiene dos capas semánticas y dos modos de render.

Capas:

- **Doctor base**: setup mínimo visible del wrapper
- **Capability doctor**: compatibilidad contractual visible con la configuración resuelta

Modos:

- **`--full`**: imprime checks completos, útil para depurar
- **`--compact`**: resume estado y solo deja problemas relevantes

Reglas duras:

- `Doctor: FAIL` sí cambia el exit del wrapper
- `Capability doctor: FAIL` **no** cambia el exit en este corte
- `UNKNOWN` no equivale a `PASS`
- `WARN` no vuelve soportada una ruta no cerrada
- `compact` no reduce validaciones; solo reduce ruido de salida

## 4) Síntomas de modo / render

### 4.1) “Esperaba detalle por check y no aparece”

Por qué pasa:

- corriste `doctor --compact`
- ese modo no imprime todos los `PASS` individuales

Qué hacer:

- repetir con `doctor --full`
- si además querés serialización estructurada, usar `doctor --dump --full`

### 4.2) “Esperaba menos ruido y doctor imprime todo”

Por qué pasa:

- corriste `doctor` sin modo explícito
- el default actual es `full`

Qué hacer:

- usar `doctor --compact`
- o fijar `TESTKIT_DOCTOR_MODE=compact` para tu shell/entorno

### 4.3) “`--dump` me da mucho output”

Por qué pasa:

- `dump` está pensado para auditoría estructurada, no para pantalla compacta

Qué hacer:

- usar `doctor --compact` para lectura humana rápida
- usar `doctor --dump --full` cuando necesitás ver config efectiva + checks serializados

## 5) Síntomas de capability comunes

### 5.1) `CLEAN_STRATEGY_UNSUPPORTED`

Por qué pasa:

- `TEST_DB_STRATEGY=clean` no forma parte del contrato operativo cerrado

Qué hacer:

- usar `TEST_DB_STRATEGY=shared`
- o `TEST_DB_STRATEGY=per_worker` si el problema real es aislamiento intra-suite

### 5.2) `MULTIWORKER_NEEDS_SUITE_CONTEXT`

Por qué pasa:

- `TEST_JOBS>1` sin `per_worker` no se puede cerrar con config visible si el target no tiene ruleset específico

Qué hacer:

- volver a `TEST_JOBS=1`
- o usar `TEST_DB_STRATEGY=per_worker` si la suite necesita DB real con paralelismo
- si querés ver el diagnóstico completo, repetir en `--full`

### 5.3) `ENGINE_NOT_CLOSED`

Por qué pasa:

- el motor efectivo no pertenece a la ruta cerrada general de esta fase

Qué hacer:

- usar MySQL si querés la ruta contractual cerrada

### 5.4) `TARGET_RULESET_PARTIAL`

Por qué pasa:

- el wrapper todavía no tiene un mapa cerrado de sensibilidad DB para ese target

Qué hacer:

- leer los checks genéricos de estrategia/motor
- usar `doctor --full` sin target para constraints visibles generales
- o cerrar un ruleset explícito para ese target

### 5.5) `MIGRATION_CONTRACT_*`

Por qué pasa:

- `migration-contract` es una ruta técnica angosta, no una suite general

Qué debe cumplirse al mismo tiempo:

- `TEST_BASELINE_MODE=snapshot`
- `TEST_DB_STRATEGY=shared`
- motor efectivo MySQL
- fuente visible de snapshot
- `TEST_JOBS=1`

Qué hacer:

- corregir la primera contradicción visible que marque capability
- preferir `doctor --full migration-contract` para leer la secuencia completa

### 5.6) `SNAPSHOT_SOURCE_NOT_VISIBLE`

Por qué pasa:

- el wrapper no ve un hint visible de snapshot por archivo o metadata/report

Qué hacer:

- declarar `TEST_BASELINE_SNAPSHOT_FILE`
- o un hint visible de metadata/report JSON compatible

## 6) Síntomas PowerShell específicos

### 6.1) `The term '' is not recognized` o `The term 'Param' is not recognized`

Por qué pasa:

- `bin/testkit.ps1` tenía una barra invertida espuria antes de `Param(...)`
- eso rompe el bloque de parámetros del script al entrar por PowerShell

Qué hacer:

- actualizar `bin/testkit.ps1` por la versión corregida
- verificar que la primera línea del archivo arranque directamente con `Param(`

### 6.2) Necesito pasar variables a `run` desde PowerShell

Lectura correcta:

- este zip no cambia el contrato general de `run`
- las mejoras nuevas están acotadas a `doctor`

Qué hacer:

- para casos simples, usar flags `-e` explícitos en `run`
- ejemplo:

```powershell
.\bin\testkit.ps1 run --rm -e TEST_MATCH=alerta testkit php runTest.php back-php
```

No des por hecho que una mejora en `doctor` implica soporte nuevo para parsing shell arbitrario en runtime.

## 7) Dump estructurado

`doctor --dump --full` expone:

- `TESTKIT_DOCTOR_MODE`
- `TESTKIT_DOCTOR_TARGET`
- `TESTKIT_DOCTOR_BASE_STATUS`
- `TESTKIT_CAPABILITY_STATUS`
- `TESTKIT_DOCTOR_BASE_CHECK_COUNT`
- `TESTKIT_DOCTOR_BASE_CHECK_<n>_STATUS`
- `TESTKIT_DOCTOR_BASE_CHECK_<n>_CODE`
- `TESTKIT_DOCTOR_BASE_CHECK_<n>_SUMMARY`
- `TESTKIT_DOCTOR_BASE_CHECK_<n>_ACTION`
- `TESTKIT_CAPABILITY_CHECK_COUNT`
- `TESTKIT_CAPABILITY_CHECK_<n>_STATUS`
- `TESTKIT_CAPABILITY_CHECK_<n>_CODE`
- `TESTKIT_CAPABILITY_CHECK_<n>_SUMMARY`
- `TESTKIT_CAPABILITY_CHECK_<n>_ACTION`

Usarlo para tests del framework y para auditoría de decisiones del wrapper.

## 8) Regla final

No acumules variables hasta “hacerlo andar”. Si capability marca `FAIL` o `UNKNOWN`, corregí primero la contradicción visible o reducí la configuración a una ruta simple.

Y no confundas render con semántica:

- `compact` es mejor para leer rápido
- `full` es mejor para corregir de verdad
