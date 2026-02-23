# 06 — Troubleshooting

## 1) `doctor` falla con “faltan back/ public_html”

Este kit asume que el repo tiene:

- `../back`
- `../public_html`

Si tu proyecto no los tiene, no uses este kit sin adaptar el contrato.

## 2) Docker no responde

- En Windows: asegurate de tener Docker Desktop abierto.
- Probá `docker info`.

## 3) Compose no existe

El kit usa Compose v2 (`docker compose`).

- `docker-compose` (v1) no es soportado.

## 4) Postgres seeds no corren

Postgres es opt‑in.

- Levantar con: `./bin/testkit --pg up -d`

## 5) Coverage vacío

- Asegurate de correr con `TEST_COVERAGE=1`.
- Si tu test hace `exit` temprano, no hay coverage.
- Podés forzar: `XDEBUG_MODE=coverage`.

## 6) Node demasiado viejo

El contenedor trae Node moderno. Si corrés sin Docker, necesitás Node 18+ (ideal 20).
