# Troubleshooting operativo

## 2) Comandos de referencia

```bash
./bin/testkit inspect config-schema
php runTest.php --help
php runTest.php back-php --list
```

## 3) Reglas duras

- una opción `--...` desconocida en `doctor` debe tratarse como error de uso
- un target desconocido en `doctor` debe tratarse como error de uso

## 6) Síntomas del runner

### 6.3) “El comando sugerido con `--list` no era confiable”

Qué hacer:

- usar `php runTest.php --help`
- usar `php runTest.php <target> --list` solo cuando el runner lo reconozca explícitamente
- para catálogo de env/flags soportados, usar `inspect config-schema`
