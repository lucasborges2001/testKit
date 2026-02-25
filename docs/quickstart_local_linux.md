# Quickstart — Local (Linux/macOS, sin Docker)

Este modo es **soportado**, pero es fácil caer en ambigüedades si tus tests usan DB.

Contrato claro:

- **Unit tests** (sin DB/HTTP): recomendados para local.
- **Integration/e2e**: recomendados con Docker (ver quickstarts Docker).

## 0) Pre-requisitos

- PHP 8.4+
- Node 18+ (recomendado 20) si corrés JS

## 1) Preparar env

El kit busca `.env.test` en:

1) `root/test/.env.test` (preferido)
2) `root/.env.test` (soportado)

```bash
cp test/.env.test.example test/.env.test
```

## 2) Correr tests (PHP)

Desde el root del repo:

```bash
php test/runTest.php
php test/runTest.php back
php test/runTest.php front-php
```

## 3) Correr tests (JS)

```bash
node test/front/runFrontTest.mjs
```

## Nota importante sobre DB

Si tus tests **tocan DB**, el `.env.test` debe apuntar a una DB accesible desde tu host.

El `.env.test.example` viene pensado para Docker (por ejemplo `DB_HOST=mysql_test`).

Opciones sin ambigüedad:

- Opción A (recomendada): correr integration/e2e en Docker.
- Opción B: mantener un env separado para host (ej.: `test/.env.test.host`) y ejecutar con:

```bash
DB_ENV_PATH=test/.env.test.host php test/runTest.php
```
