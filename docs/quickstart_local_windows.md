# Quickstart — Local (Windows, sin Docker)

Este modo es **soportado**, pero en Windows es común que haya ambigüedades si mezclás Node/PHP/DB.

Contrato claro:

- **Unit tests** (sin DB/HTTP): recomendados para local.
- **Integration/e2e**: recomendados con Docker (ver quickstart Docker Windows).

## 0) Pre-requisitos

- PHP 8.4+ instalado en PATH
- Node 18+ instalado en PATH

## 1) Preparar env

`.env.test` se busca en:

1) `root\test\.env.test` (preferido)
2) `root\.env.test` (soportado)

```powershell
Copy-Item .\test\.env.test.example .\test\.env.test
```

## 2) Correr tests

Desde el root del repo:

```powershell
php .\test\runTest.php
php .\test\runTest.php back
php .\test\runTest.php front-php
node .\test\front\run.mjs
```

## Nota importante sobre DB

Si tus tests **tocan DB**, el `.env.test` debe apuntar a una DB accesible desde tu host.

El ejemplo viene pensado para Docker (por ejemplo `DB_HOST=mysql_test`).

Opciones sin ambigüedad:

- Opción A (recomendada): correr integration/e2e con Docker.
- Opción B: un env separado para host (ej.: `test\.env.test.host`) y ejecutar con:

```powershell
$env:DB_ENV_PATH = "test\.env.test.host"; php .\test\runTest.php
```
