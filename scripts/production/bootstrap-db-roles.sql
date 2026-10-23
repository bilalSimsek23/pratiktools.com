-- Production database role bootstrap for PratikTools. Idempotent.
--
-- Run ONCE per database with the provider's administrator credential
-- (Laravel Cloud / Neon: the injected owner, a neon_superuser member with
-- BYPASSRLS). That credential is used for nothing else and never goes into
-- the application's runtime configuration.
--
-- Creates or hardens three LOGIN, NOBYPASSRLS roles:
--   owner     owns the schema objects, runs migrations only
--   app       runtime (web, queue, scheduler); row-level security applies
--   platform  Super Admin; only the column grants migrations give it
--
-- Inputs come from the environment through psql's \getenv, so passwords
-- never appear in a command line or process list. Requires psql 15+.
--   OWNER_ROLE APP_ROLE PLATFORM_ROLE              (role names)
--   OWNER_PASSWORD APP_PASSWORD PLATFORM_PASSWORD  (required when creating)
--   ROTATE_PASSWORDS=1                             (also reset passwords of existing roles)

\set ON_ERROR_STOP on
\set QUIET on

\getenv owner_role OWNER_ROLE
\getenv app_role APP_ROLE
\getenv platform_role PLATFORM_ROLE
\getenv owner_password OWNER_PASSWORD
\getenv app_password APP_PASSWORD
\getenv platform_password PLATFORM_PASSWORD
\getenv rotate ROTATE_PASSWORDS

-- Hand the values to SQL as session settings (cleared at the end).
SELECT set_config('bootstrap.owner_role', :'owner_role', false),
       set_config('bootstrap.app_role', :'app_role', false),
       set_config('bootstrap.platform_role', :'platform_role', false),
       set_config('bootstrap.owner_password', coalesce(:'owner_password', ''), false),
       set_config('bootstrap.app_password', coalesce(:'app_password', ''), false),
       set_config('bootstrap.platform_password', coalesce(:'platform_password', ''), false),
       set_config('bootstrap.rotate', coalesce(:'rotate', '0'), false) \gset dummy_

DO $bootstrap$
DECLARE
    spec record;
    exists_already boolean;
    password text;
BEGIN
    IF current_setting('bootstrap.owner_role') IN ('', current_user)
       OR current_setting('bootstrap.app_role') IN ('', current_user)
       OR current_setting('bootstrap.platform_role') IN ('', current_user) THEN
        RAISE EXCEPTION 'Role names must be set and differ from the administrator running this script.';
    END IF;

    FOR spec IN
        SELECT current_setting('bootstrap.owner_role') AS name, current_setting('bootstrap.owner_password') AS pw
        UNION ALL SELECT current_setting('bootstrap.app_role'), current_setting('bootstrap.app_password')
        UNION ALL SELECT current_setting('bootstrap.platform_role'), current_setting('bootstrap.platform_password')
    LOOP
        SELECT true INTO exists_already FROM pg_roles WHERE rolname = spec.name;
        password := spec.pw;

        IF exists_already IS NULL THEN
            IF password = '' OR length(password) < 24 THEN
                RAISE EXCEPTION 'A password of at least 24 characters is required to create role %.', spec.name;
            END IF;
            EXECUTE format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS INHERIT PASSWORD %L', spec.name, password);
            RAISE NOTICE 'created role %', spec.name;
        ELSE
            EXECUTE format('ALTER ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS', spec.name);
            IF current_setting('bootstrap.rotate') = '1' THEN
                IF password = '' OR length(password) < 24 THEN
                    RAISE EXCEPTION 'ROTATE_PASSWORDS=1 needs a password of at least 24 characters for %.', spec.name;
                END IF;
                EXECUTE format('ALTER ROLE %I PASSWORD %L', spec.name, password);
                RAISE NOTICE 'rotated password of role %', spec.name;
            ELSE
                RAISE NOTICE 'role % exists; attributes hardened, password unchanged', spec.name;
            END IF;
        END IF;
        exists_already := NULL;

        -- Neon grants neon_superuser (BYPASSRLS) to console-created roles.
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'neon_superuser')
           AND pg_has_role(spec.name, 'neon_superuser', 'MEMBER') THEN
            EXECUTE format('REVOKE neon_superuser FROM %I', spec.name);
            RAISE NOTICE 'revoked neon_superuser from %', spec.name;
        END IF;
    END LOOP;

    -- Database: only the three roles (and the administrator) may connect.
    EXECUTE format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database());
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO %I, %I, %I', current_database(),
        current_setting('bootstrap.owner_role'), current_setting('bootstrap.app_role'), current_setting('bootstrap.platform_role'));

    -- Schema: the owner creates objects; runtime roles only use them.
    REVOKE CREATE ON SCHEMA public FROM PUBLIC;
    EXECUTE format('GRANT USAGE, CREATE ON SCHEMA public TO %I', current_setting('bootstrap.owner_role'));
    EXECUTE format('GRANT USAGE ON SCHEMA public TO %I, %I', current_setting('bootstrap.app_role'), current_setting('bootstrap.platform_role'));
    EXECUTE format('REVOKE CREATE ON SCHEMA public FROM %I, %I', current_setting('bootstrap.app_role'), current_setting('bootstrap.platform_role'));

    -- A stuck transaction must not hold tenant rows locked forever.
    EXECUTE format('ALTER ROLE %I SET idle_in_transaction_session_timeout = %L', current_setting('bootstrap.app_role'), '5min');
    EXECUTE format('ALTER ROLE %I SET idle_in_transaction_session_timeout = %L', current_setting('bootstrap.platform_role'), '5min');
END
$bootstrap$;

-- Forget the passwords in this session.
SELECT set_config('bootstrap.owner_password', '', false), set_config('bootstrap.app_password', '', false),
       set_config('bootstrap.platform_password', '', false) \gset dummy_

\set QUIET off
\echo 'Roles after bootstrap (all flags must be f):'
SELECT rolname, rolcanlogin AS login, rolsuper AS superuser, rolbypassrls AS bypass_rls, rolcreaterole AS create_role, rolcreatedb AS create_db,
       pg_has_role(rolname, 'pg_read_all_data', 'MEMBER') AS read_all_data
FROM pg_roles
WHERE rolname IN (current_setting('bootstrap.owner_role'), current_setting('bootstrap.app_role'), current_setting('bootstrap.platform_role'))
ORDER BY rolname;

\echo 'Tables in public not owned by the owner role (must be 0 before the first migration and after):'
SELECT count(*) AS foreign_owned_tables FROM pg_tables WHERE schemaname = 'public' AND tableowner <> current_setting('bootstrap.owner_role');

DO $verify$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_roles
        WHERE rolname IN (current_setting('bootstrap.owner_role'), current_setting('bootstrap.app_role'), current_setting('bootstrap.platform_role'))
          AND (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR NOT rolcanlogin)
    ) THEN
        RAISE EXCEPTION 'Verification failed: a bootstrap role has a forbidden attribute.';
    END IF;
END
$verify$;
\echo 'OK'
