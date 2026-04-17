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
./bin/testkit doctor
./bin/testkit doctor --dump
./bin/testkit doctor migration-contract
./bin/testkit inspect latest
./bin/testkit inspect failure
./bin/testkit inspect seed-state
./bin/testkit inspect concurrency
```

### PowerShell

```powershell
.\bin\testkit.ps1 doctor
.\bin\testkit.ps1 doctor --dump
.\bin\testkit.ps1 doctor migration-contract
.\bin\testkit.ps1 inspect latest
.\bin\testkit.ps1 inspect failure
.\bin\testkit.ps1 inspect seed-state
.\bin\testkit.ps1 inspect concurrency
```

## 3) Cómo leer `doctor`

`doctor` tiene dos capas distintas:

- **Doctor base**: setup mínimo visible del wrapper
- **Capability doctor**: compatibilidad contractual visible con la configuración resuelta

Reglas duras:

- `Doctor: FAIL` sí cambia el exit del wrapper
- `Capability doctor: FAIL` **no** cambia el exit en este corte
- `UNKNOWN` no equivale a `PASS`
- `WARN` no vuelve soportada una ruta no cerrada

## 4) Síntomas de capability comunes

### 4.1) `CLEAN_STRATEGY_UNSUPPORTED`

Por qué pasa:

- `TEST_DB_STRATEGY=clean` no forma parte del contrato operativo cerrado

Qué hacer:

- usar `TEST_DB_STRATEGY=shared`
- o `TEST_DB_STRATEGY=per_worker` si el problema real es aislamiento intra-suite

### 4.2) `MULTIWORKER_NEEDS_SUITE_CONTEXT`

Por qué pasa:

- `TEST_JOBS>1` sin `per_worker` no se puede cerrar con config visible si el target no tiene ruleset específico

Qué hacer:

- volver a `TEST_JOBS=1`
- o usar `TEST_DB_STRATEGY=per_worker` si la suite necesita DB real con paralelismo

### 4.3) `ENGINE_NOT_CLOSED`

Por qué pasa:

- el motor efectivo no pertenece a la ruta cerrada general de esta fase

Qué hacer:

- usar MySQL si querés la ruta contractual cerrada

### 4.4) `TARGET_RULESET_PARTIAL`

Por qué pasa:

- el wrapper todavía no tiene un mapa cerrado de sensibilidad DB para ese target

Qué hacer:

- leer los checks genéricos de estrategia/motor
- usar `doctor` sin target para constraints visibles generales
- o cerrar un ruleset explícito para ese target

### 4.5) `MIGRATION_CONTRACT_*`

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

### 4.6) `SNAPSHOT_SOURCE_NOT_VISIBLE`

Por qué pasa:

- el wrapper no ve un hint visible de snapshot por archivo o metadata/report

Qué hacer:

- declarar `TEST_BASELINE_SNAPSHOT_FILE`
- o un hint visible de metadata/report JSON compatible

## 5) Síntomas PowerShell específicos

### 5.1) `The term '' is not recognized` o `The term 'Param' is not recognized`

Por qué pasa:

- `bin/testkit.ps1` tenía una barra invertida espuria antes de `Param(...)`
- eso rompe el bloque de parámetros del script al entrar por PowerShell

Qué hacer:

- actualizar `bin/testkit.ps1` por la versión corregida
- verificar que la primera línea del archivo arranque directamente con `Param(`

### 5.2) `exec: TEST_MATCH="alerta" php ...: not found`

Por qué pasa:

- Docker recibió toda la tail command como si fuera el nombre del ejecutable
- eso ocurre cuando PowerShell pasa la tail command como un único string y el wrapper no la normaliza

Qué hacer:

- usar la versión corregida de `bin/testkit.ps1`
- correr, por ejemplo:

```powershell
.in	estkit.ps1 run --rm testkit 'TEST_MATCH="alerta" php runTest.php back-php'
```

Lectura correcta:

- el wrapper reescribe `runTest.php` a `/workspace/testkit/runTest.php`
- y ejecuta la tail command con `sh -lc` dentro del contenedor

## 6) Dump estructurado

`doctor --dump` expone:

- `TESTKIT_CAPABILITY_STATUS`
- `TESTKIT_CAPABILITY_CHECK_COUNT`
- `TESTKIT_CAPABILITY_CHECK_<n>_STATUS`
- `TESTKIT_CAPABILITY_CHECK_<n>_CODE`
- `TESTKIT_CAPABILITY_CHECK_<n>_SUMMARY`
- `TESTKIT_CAPABILITY_CHECK_<n>_ACTION`

Usarlo para tests del framework y para auditoría de decisiones del wrapper.

## 7) Regla final

No acumules variables hasta “hacerlo andar”. Si capability marca `FAIL` o `UNKNOWN`, corregí primero la contradicción visible o reducí la configuración a una ruta simple.
