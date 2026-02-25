# 01 — Quickstart (índice)

Elegí **un único camino** y seguí el doc correspondiente (sin ambigüedades):

## Docker

- Linux/macOS (bash): `quickstart_docker_linux.md`
- Windows (PowerShell): `quickstart_docker_windows.md`

## Local (sin Docker)

- Linux/macOS: `quickstart_local_linux.md`
- Windows: `quickstart_local_windows.md`

---

Notas:

- El env de tests es `.env.test` y se busca en:
  1) `root/test/.env.test` (preferido)
  2) `root/.env.test` (soportado)
- Si corrés con Docker, **siempre** usá `bin/testkit` / `bin/testkit.ps1` (no ejecutes `docker compose` directo).


---

## Runners incluidos (para que no haya dudas con nombres)

- Backend (PHP): `test/back/runTestBack.php`
- Front (PHP): `test/front/runFrontTest.php`
- Front (JS): `test/front/runFrontTest.mjs`
- Orquestador: `test/runTest.php` (recomendado)
