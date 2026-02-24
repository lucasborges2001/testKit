# 02 — Seeds (MySQL + Postgres)

Este kit define una convención simple y robusta:

- **MySQL**: siempre disponible (default).
- **Postgres**: opcional (solo si levantás con `--pg`).

Las seeds viven dentro de `test/` para que el kit sea 100% portable.

---

## 1) ¿Las seeds quedan por archivo o todo en uno?

**Por archivo.**

Ventajas:

- orden explícito (con prefijos)
- podés agregar/quitar una seed sin tocar un bloque gigante
- más fácil revisar diffs en git

### Regla de ejecución

Los archivos `.sql` se ejecutan en **orden alfabético**.

Recomendación: usar prefijos numéricos:

- `001_schema.sql`
- `010_seed_base.sql`
- `020_seed_usuarios.sql`
- `900_test_only.sql`

---

## 2) Dónde van

- MySQL: `test/seeds/mysql/*.sql`
- Postgres: `test/seeds/pgsql/*.sql`

---

## 3) Cómo correr seeds

### MySQL (default)

```bash
cd test
./bin/testkit up -d
./scripts/seed.sh
```

Windows (PowerShell):

```powershell
cd .\test
.\bin\testkit.ps1 up -d
.\scripts\seed.ps1
```

### Activar Postgres + seeds

```bash
cd test
./bin/testkit --pg up -d
./scripts/seed.sh
```

Windows (PowerShell):

```powershell
cd .\test
.\bin\testkit.ps1 --pg up -d
.\scripts\seed.ps1
```

---

## 4) Cómo escribir seeds “compatibles”

- Evitá `DROP DATABASE` / `CREATE DATABASE` dentro de las seeds.
- Preferí `CREATE TABLE IF NOT EXISTS` si tu flujo lo permite.
- Si necesitás reset total, usá `./scripts/db_reset.sh` (baja volúmenes).

> Nota: los scripts de seed usan las variables **dentro de los contenedores** (`MYSQL_DATABASE`, `POSTGRES_DB`, etc.),
> así que no dependés de exportar variables en tu host.

---

## 5) Buenas prácticas

- **Schema separado de data**: primero `001_schema.sql`, luego `010_seed.sql`.
- **Datos mínimos**: solo lo necesario para tests.
- **Datos deterministas**: IDs estables o claves únicas claras.
