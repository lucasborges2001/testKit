#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

echo "==> Seeding MySQL…"
for f in $(ls -1 seeds/mysql/*.sql 2>/dev/null || true); do
  echo "   - $f"
  ./bin/testkit exec -T mysql_test sh -lc \
    'mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${MYSQL_DATABASE}"' < "$f"
done

# Postgres opcional: existe si levantaste con --pg
if ./bin/testkit ps --services | grep -q '^postgres_test$'; then
  echo "==> Seeding Postgres…"
  for f in $(ls -1 seeds/pgsql/*.sql 2>/dev/null || true); do
    echo "   - $f"
    ./bin/testkit exec -T postgres_test sh -lc \
      'PGPASSWORD="${POSTGRES_PASSWORD}" psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -f -' < "$f"
  done
else
  echo "==> Postgres no activo (ok). Levantar con: ./bin/testkit --pg up -d"
fi
