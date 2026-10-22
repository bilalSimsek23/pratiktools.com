#!/bin/sh
# Creates the three database roles and the application databases.
#
# saas_owner    owns every table and runs migrations. Never used at runtime.
# saas_app      runtime role for HTTP, queue and scheduler. Not a table owner,
#               NOBYPASSRLS, so PostgreSQL row-level security always applies.
# saas_platform read-mostly role for the Super Admin panel. Gets explicit,
#               column-level grants only; no access to tenant PII tables.
#
# Used by the postgres container (docker-entrypoint-initdb.d) and by CI.
# Passwords come from the environment; the defaults are for local use only.
set -eu

: "${POSTGRES_USER:=postgres}"
: "${SAAS_DATABASES:=saas saas_testing}"
: "${DB_OWNER_PASSWORD:=local_owner_password}"
: "${DB_APP_PASSWORD:=local_app_password}"
: "${DB_PLATFORM_PASSWORD:=local_platform_password}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'saas_owner') THEN
    CREATE ROLE saas_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '${DB_OWNER_PASSWORD}';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'saas_app') THEN
    CREATE ROLE saas_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '${DB_APP_PASSWORD}';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'saas_platform') THEN
    CREATE ROLE saas_platform LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS PASSWORD '${DB_PLATFORM_PASSWORD}';
  END IF;
END
\$\$;
SQL

for db in $SAAS_DATABASES; do
  exists=$(psql -tA --username "$POSTGRES_USER" --dbname postgres -c "SELECT 1 FROM pg_database WHERE datname = '$db'")
  if [ "$exists" != "1" ]; then
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE \"$db\" OWNER saas_owner"
  fi
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<SQL
ALTER SCHEMA public OWNER TO saas_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM saas_app, saas_platform;
GRANT USAGE ON SCHEMA public TO saas_app, saas_platform;
REVOKE ALL ON DATABASE "$db" FROM PUBLIC;
GRANT CONNECT ON DATABASE "$db" TO saas_owner, saas_app, saas_platform;
SQL
done
