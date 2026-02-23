# 04 — Escribir tests (convenciones)

La idea del kit es que un test sea **fácil de leer, fácil de correr**, y que deje claro:

- **qué prueba**
- **qué datos usa** (y si toca DB)
- **qué depende** (servicios, endpoints, tablas, archivos)
- **qué categoría es** (unit / integration / e2e)

## 1) Naming + metadata (obligatorio)

### BACK (PHP)

Ubicación recomendada:

- `test/back/tests/unit/...`
- `test/back/tests/integration/...`

Regla:

- el nombre del archivo debe describir el comportamiento
- arriba del archivo, un header de metadata

Ejemplo (template):

```php
<?php
/**
 * TEST: Usuario / crear
 * SCOPE: integration
 * QUÉ PRUEBA:
 *   - crea un usuario válido
 *   - rechaza email duplicado
 * DEPENDE DE:
 *   - DB mysql_test (tabla usuario)
 * DATOS:
 *   - seeds: 010_seed.sql
 */
```

> `SCOPE` es clave: te permite filtrar con `TEST_SCOPE=unit` / `integration`.

### FRONT (JS)

Ubicación recomendada:

- `test/front/tests/unit/...`
- `test/front/tests/integration/...`

Ejemplo:

```js
/**
 * TEST: public_html / login form
 * SCOPE: unit
 * QUÉ PRUEBA:
 *   - valida campos requeridos
 * DEPENDE DE:
 *   - sin DB
 */
```

## 2) Qué entra en unit vs integration

- **unit**: sin red, sin DB, sin filesystem (o mocks)
- **integration**: toca DB / HTTP / filesystem
- **e2e**: flujo completo (si lo agregás, mantenelo opt‑in)

## 3) Cómo describir lo que prueba (regla práctica)

En “QUÉ PRUEBA” escribí **criterios verificables**, no intenciones.

✅ Bien:
- “rechaza email duplicado con código 409”

❌ Vago:
- “funciona bien el alta”

## 4) Estructura sugerida de un test

1) Arrange: preparar datos
2) Act: ejecutar
3) Assert: validar
4) Cleanup (solo si hace falta)

## 5) Checklist antes de commitear

- [ ] El test tiene metadata completa (TEST/SCOPE/QUÉ PRUEBA)
- [ ] Se puede correr solo (o documenta dependencias)
- [ ] No depende de orden aleatorio
- [ ] Si toca DB, indica qué seeds necesita
