# Troubleshooting operativo

## Síntomas cubiertos en este corte

### 1) `doctor` marca `AGGREGATE_TARGET_NOISY_FIRST_DIAG`

Lectura correcta:

- el target es válido
- el problema no es invalidez, sino ruido diagnóstico

Qué hacer:

- bajar a una suite concreta (`back-php`, `front-js`, etc.)

### 2) `doctor` marca `TARGET_CATEGORY_MISMATCH`

Lectura correcta:

- pediste un target por categoría
- pero también dejaste `TEST_CATEGORY` con otro valor visible

Qué hacer:

- quitar `TEST_CATEGORY`
- o alinearlo con el target pedido

### 3) `doctor` marca `MULTIWORKER_SHARED_VISIBLE_RISK`

Lectura correcta:

- `TEST_JOBS>1` con `shared` no queda cerrado como ruta segura

Qué hacer:

- volver a `TEST_JOBS=1`
- o usar `TEST_DB_STRATEGY=per_worker`

### 4) `doctor` marca `PER_WORKER_SINGLE_WORKER_OVERCONFIGURED`

Lectura correcta:

- no es contradicción dura
- pero agrega complejidad sin ganancia visible

Qué hacer:

- simplificar a `shared` si no necesitás workers múltiples

### 5) Necesito ver el esquema soportado real

Usá:

```bash
./bin/testkit inspect config-schema --json
```
