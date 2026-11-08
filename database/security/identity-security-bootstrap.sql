-- Provisioned by the database administrator before Laravel identity migrations.
-- Runtime credentials never own this schema or read key material.

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;

CREATE SCHEMA IF NOT EXISTS identity AUTHORIZATION dawineks_owner;
ALTER SCHEMA identity OWNER TO dawineks_owner;
CREATE SCHEMA IF NOT EXISTS security AUTHORIZATION dawineks_owner;
ALTER SCHEMA security OWNER TO dawineks_owner;

SET ROLE dawineks_owner;

CREATE TABLE IF NOT EXISTS security.context_keys (
    key_id text PRIMARY KEY,
    key_material bytea NOT NULL CHECK (octet_length(key_material) = 32),
    is_active boolean NOT NULL DEFAULT false
);

CREATE UNIQUE INDEX IF NOT EXISTS context_keys_one_active_idx
    ON security.context_keys (is_active)
    WHERE is_active;

CREATE OR REPLACE FUNCTION security.valid_principal_context()
RETURNS boolean
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, security
AS $$
    SELECT COALESCE(
        context.principal_id ~ '^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
        AND context.signature ~ '^[0-9a-fA-F]{64}$'
        AND encode(
            public.hmac(
                convert_to('principal:' || lower(context.principal_id) || ':' || txid_current()::text, 'UTF8'),
                (SELECT key_material FROM security.context_keys WHERE is_active),
                'sha256'
            ),
            'hex'
        ) = lower(context.signature),
        false
    )
    FROM (
        SELECT current_setting('app.principal_id', true) AS principal_id,
               current_setting('app.principal_signature', true) AS signature
    ) AS context
$$;

CREATE OR REPLACE FUNCTION security.current_principal_id()
RETURNS uuid
LANGUAGE sql
STABLE
AS $$
    SELECT CASE WHEN security.valid_principal_context()
                THEN lower(current_setting('app.principal_id', true))::uuid
           END
$$;

RESET ROLE;

REVOKE ALL ON SCHEMA security FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA security FROM PUBLIC;
REVOKE ALL ON FUNCTION security.valid_principal_context() FROM PUBLIC;
REVOKE ALL ON FUNCTION security.current_principal_id() FROM PUBLIC;
GRANT USAGE ON SCHEMA security TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION security.valid_principal_context() TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION security.current_principal_id() TO dawineks_runtime;
