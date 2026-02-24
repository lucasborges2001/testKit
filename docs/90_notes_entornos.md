# Notas / ejemplos (NO contractuales)

Este archivo existe para dejarte “miguitas de pan” (a vos o a alguien que lo descargue) sobre
posibles variantes, setups y patrones.

**No forma parte del contrato del TestKit**. Si entra en conflicto con los docs de quickstart,
seeds, coverage, etc., lo contractual es lo que está en `docs/`.

# Entornos Docker (Pivote)

Este repo usa **un único** `docker-compose.yml` y se cambia de entorno con `--env-file`.

- `env.prod`: producción
- `env.debug`: réplica local de prod (para troubleshooting y desarrollo)
- `env.test`: mismo stack que debug, pero con **reset de DBs** cuando quieras (ideal para tests)

---

## 1) Levantar / bajar

```bash
# debug
docker compose --env-file env.debug up -d --build
docker compose --env-file env.debug down

# test
docker compose --env-file env.test up -d --build
docker compose --env-file env.test down

# prod
docker compose --env-file env.prod up -d --build
docker compose --env-file env.prod down
```

---

## 2) Reset de DBs en test (1 comando)

En `env.test`, MySQL y Postgres usan **named volumes**.  
Para resetear (borrar volumen + recrear + re-seed/init):

```bash
docker compose --env-file env.test down -v --remove-orphans
docker compose --env-file env.test up -d --build
```

> `-v` borra volúmenes de **MySQL/Postgres** del entorno test.  
> Influx usa bind-mount y **no se borra** con este reset.

---

## 3) MySQL: restaurar dump automáticamente (debug/test)

Objetivo: cuando el volumen de MySQL está vacío (primer init), cargar un dump canónico.

### 3.1 Archivo del dump
Copiar el dump en:

```
docker/mysql/backups/Riego_backup_server_2026-02-13.sql
```

### 3.2 Activación por entorno
En `env.debug` y `env.test`:

- `MYSQL_RESTORE_FILENAME=Riego_backup_server_2026-02-13.sql`

En `env.prod` **no** se define (para evitar imports accidentales).

### 3.3 Forzar reimport
Como MySQL ejecuta init scripts **solo con datadir vacío**, para reimportar:

```bash
docker compose --env-file env.debug down -v --remove-orphans
docker compose --env-file env.debug up -d --build
```

---

## 4) InfluxDB: bases obligatorias (auto-init)

Este proyecto garantiza la creación (idempotente) de estas bases al levantar el stack:

- MQTT
- lev_conf
- Log_sis
- pivote_MQTT
- pivote_log
- pivote_conf

Se configura con:

- `INFLUX_INIT_DBS=MQTT,lev_conf,Log_sis,pivote_MQTT,pivote_log,pivote_conf`

### Verificar
```bash
docker exec -it <slug>_influxdb influx -execute "SHOW DATABASES"
```

### Re-ejecutar el init sin recrear todo
```bash
docker compose --env-file env.debug run --rm influx_init
```

---

## 5) Nota importante sobre `$` en docker-compose

Docker Compose expande variables del host (ej: `$db`) dentro del YAML.  
Para usar variables de shell **dentro del contenedor**, se debe escapar como `$$db`.

En este repo, `influx_init` usa `$$db` para evitar el warning:
- `The "db" variable is not set`

---

## 6) Puertos sugeridos (debug/test separados)

- prod: Influx `8086`
- debug: Influx `18086`
- test: Influx `28086`

Esto permite correr debug y test simultáneamente sin colisiones.
