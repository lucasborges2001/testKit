# Seeds y schema de testing

Este repo separa dos responsabilidades:

- **Schema**: define tablas, indices, constraints. **No** incluye `DROP DATABASE` / `CREATE DATABASE`.
- **Seeds**: fixtures minimos para que los tests tengan datos. Idealmente **solo INSERT** (y/o UPDATE puntual).

## Estructura

```
test/
  schema/
    mysql/
      001_schema.sql
      002_constraints.sql
  seeds/
    mysql/
      010_seed_min.sql
      020_seed_indice.sql
      030_seed_permisos.sql
```

> Los scripts aplican los archivos en orden alfabetico.

## Reglas para schema

- Mantenerlo *squasheado* para fresh install (`001_schema.sql`) ayuda a tests reproducibles.
- No metas `DROP DATABASE` / `CREATE DATABASE` en schema: eso pertenece al **reset**.

## Reglas para seeds

1) **Seeds minimos**: solo lo que destraba el proximo FAIL real.

2) **IDs explicitos** (si podes):
   - Evitas que un `AUTO_INCREMENT` cambie por orden de inserts.

3) **Datos deterministas**:
   - Fechas fijas (o calculadas dentro del test), no `NOW()` en seeds.
   - Emails/usuarios/libros con nombres constantes.

4) **Evitar side-effects**:
   - No generar datos gigantes que ralenticen tests.

5) **Compatibilidad con reset modes**:
   - `dropdb`: DB vacia (recomendado).
   - `fast`: `TRUNCATE` (rapido; requiere que el schema ya exista).
   - `heavy`: en Docker resetea volumenes; en local equivale a `dropdb`.

## Flujo recomendado

1) Reset **dropdb**.
2) Aplicar schema + seeds.
3) Correr tests.
4) Si un test falla por datos faltantes:
   - agregar el fixture minimo en un nuevo archivo `0xx_...sql`.
5) Repetir.

## Estrategia shared vs per_worker

Si corres tests en paralelo:

- `TEST_DB_STRATEGY=shared`: todos los workers comparten la misma DB.
- `TEST_DB_STRATEGY=per_worker`: crea DB por worker:
  - base: `TEST_MYSQL_DB=app_test`
  - sufijo: `TEST_DB_WORKER_SUFFIX_FORMAT=_w%02d`
  - ejemplo: `app_test_w01`, `app_test_w02`, ...

En `per_worker`, el reset/seed se aplica a cada DB.
