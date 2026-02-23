# 03 — Coverage (PHP)

El kit usa **Xdebug** dentro del contenedor `testkit`.

## Activar

```bash
cd test
./bin/testkit run --rm \
  -e TEST_COVERAGE=1 \
  -e TEST_COVERAGE_FORMAT=lcov \
  testkit php runTest.php back
```

## Salida

- `test/_out/coverage/php_back/lcov.info`
- `test/_out/coverage/php_public/lcov.info`

## Formatos

`TEST_COVERAGE_FORMAT`:

- `lcov`
- `json`
- `both`

## HTML (opcional)

Si tenés `genhtml` (paquete `lcov`) en tu máquina:

```bash
genhtml -o test/_out/coverage/html_back test/_out/coverage/php_back/lcov.info
```
