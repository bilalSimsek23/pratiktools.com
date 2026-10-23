#!/bin/sh
# Wrapper for bootstrap-db-roles.sql. Run from a trusted machine, never from
# the application. Connection comes from the standard libpq variables of the
# provider's administrator credential:
#
#   export PGHOST=... PGPORT=5432 PGDATABASE=... PGUSER=... PGSSLMODE=require
#   export PGPASSWORD=...                     # administrator, this run only
#   export OWNER_PASSWORD=... APP_PASSWORD=... PLATFORM_PASSWORD=...
#   sh scripts/production/bootstrap-db-roles.sh
#
# Use the direct endpoint, not the "-pooler" host. Role names default to
# saas_owner / saas_app / saas_platform (override with OWNER_ROLE, APP_ROLE,
# PLATFORM_ROLE). Re-running is safe; ROTATE_PASSWORDS=1 also resets the
# passwords of existing roles.
set -eu

: "${PGHOST:?PGHOST is required}"
: "${PGUSER:?PGUSER (administrator) is required}"
: "${PGDATABASE:?PGDATABASE is required}"
export PGSSLMODE="${PGSSLMODE:-require}"
export OWNER_ROLE="${OWNER_ROLE:-saas_owner}"
export APP_ROLE="${APP_ROLE:-saas_app}"
export PLATFORM_ROLE="${PLATFORM_ROLE:-saas_platform}"
export ROTATE_PASSWORDS="${ROTATE_PASSWORDS:-0}"
# Defined (possibly empty) so \getenv always sets the psql variables.
export OWNER_PASSWORD="${OWNER_PASSWORD:-}" APP_PASSWORD="${APP_PASSWORD:-}" PLATFORM_PASSWORD="${PLATFORM_PASSWORD:-}"

case "$PGHOST" in
  *-pooler*) echo "Refusing: use the direct endpoint, not the connection pooler." >&2; exit 1 ;;
esac

major=$(psql --version | sed -E 's/[^0-9]*([0-9]+).*/\1/')
if [ "$major" -lt 15 ]; then
  echo "psql 15 or newer is required (\\getenv)." >&2
  exit 1
fi

exec psql --no-psqlrc -X -f "$(dirname "$0")/bootstrap-db-roles.sql"
