-- Test-only fixtures for the G2 PostgreSQL row-security proof.
-- These are not hotel or tenant business tables and are not run by app migrations.
-- Execute as a database administrator after the dawineks_owner role exists.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE SCHEMA IF NOT EXISTS g2_proof AUTHORIZATION dawineks_owner;
ALTER SCHEMA g2_proof OWNER TO dawineks_owner;
CREATE SCHEMA IF NOT EXISTS tenant AUTHORIZATION dawineks_owner;
ALTER SCHEMA tenant OWNER TO dawineks_owner;

SET ROLE dawineks_owner;

CREATE TABLE IF NOT EXISTS g2_proof.tenant_records (
    record_id uuid PRIMARY KEY,
    account_id uuid NOT NULL,
    property_id uuid NOT NULL,
    payload text NOT NULL
);

CREATE TABLE IF NOT EXISTS g2_proof.context_keys (
    key_id text PRIMARY KEY CHECK (key_id = 'active'),
    key_material bytea NOT NULL CHECK (octet_length(key_material) = 32)
);

CREATE OR REPLACE FUNCTION g2_proof.valid_context()
RETURNS boolean
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, g2_proof, public
AS $$
    SELECT COALESCE(
        context.account_id ~ '^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
        AND context.property_id ~ '^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
        AND context.signature ~ '^[0-9a-fA-F]{64}$'
        AND encode(
            public.hmac(
                convert_to(lower(context.account_id) || ':' || lower(context.property_id), 'UTF8'),
                (SELECT key_material FROM g2_proof.context_keys WHERE key_id = 'active'),
                'sha256'
            ),
            'hex'
        ) = lower(context.signature),
        false
    )
    FROM (
        SELECT current_setting('app.account_id', true) AS account_id,
               current_setting('app.property_id', true) AS property_id,
               current_setting('app.context_signature', true) AS signature
    ) AS context
$$;

CREATE OR REPLACE FUNCTION g2_proof.current_account_id()
RETURNS uuid
LANGUAGE sql
STABLE
AS $$
    SELECT CASE WHEN g2_proof.valid_context()
                THEN lower(current_setting('app.account_id', true))::uuid
           END
$$;

CREATE OR REPLACE FUNCTION g2_proof.current_property_id()
RETURNS uuid
LANGUAGE sql
STABLE
AS $$
    SELECT CASE WHEN g2_proof.valid_context()
                THEN lower(current_setting('app.property_id', true))::uuid
           END
$$;

ALTER TABLE g2_proof.tenant_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE g2_proof.tenant_records FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_account_property_scope ON g2_proof.tenant_records;
CREATE POLICY tenant_account_property_scope
    ON g2_proof.tenant_records
    FOR ALL
    TO dawineks_runtime
    USING (
        account_id = g2_proof.current_account_id()
        AND property_id = g2_proof.current_property_id()
    )
    WITH CHECK (
        account_id = g2_proof.current_account_id()
        AND property_id = g2_proof.current_property_id()
    );

CREATE OR REPLACE VIEW g2_proof.tenant_records_view
WITH (security_invoker = true)
AS
SELECT record_id, account_id, property_id, payload
FROM g2_proof.tenant_records;

RESET ROLE;

REVOKE ALL ON SCHEMA g2_proof FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO dawineks_runtime;
REVOKE ALL ON FUNCTION g2_proof.current_account_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION g2_proof.current_property_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION g2_proof.valid_context() FROM PUBLIC;
REVOKE ALL ON TABLE g2_proof.context_keys FROM PUBLIC, dawineks_runtime;
GRANT USAGE ON SCHEMA g2_proof TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION g2_proof.valid_context() TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION g2_proof.current_account_id() TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION g2_proof.current_property_id() TO dawineks_runtime;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE g2_proof.tenant_records TO dawineks_runtime;
GRANT SELECT ON TABLE g2_proof.tenant_records_view TO dawineks_runtime;
