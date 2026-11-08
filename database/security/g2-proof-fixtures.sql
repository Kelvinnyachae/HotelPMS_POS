-- Test-only fixtures for the G2 PostgreSQL row-security proof.
-- These are not hotel or tenant business tables and are not run by app migrations.
-- Execute as a database administrator after the dawineks_owner role exists.

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE SCHEMA IF NOT EXISTS g2_proof AUTHORIZATION dawineks_owner;
ALTER SCHEMA g2_proof OWNER TO dawineks_owner;
CREATE SCHEMA IF NOT EXISTS tenant AUTHORIZATION dawineks_owner;
ALTER SCHEMA tenant OWNER TO dawineks_owner;
CREATE SCHEMA IF NOT EXISTS identity AUTHORIZATION dawineks_owner;
ALTER SCHEMA identity OWNER TO dawineks_owner;

SET ROLE dawineks_owner;

CREATE TABLE IF NOT EXISTS g2_proof.tenant_records (
    record_id uuid PRIMARY KEY,
    account_id uuid NOT NULL,
    property_id uuid NOT NULL,
    payload text NOT NULL
);

CREATE TABLE IF NOT EXISTS g2_proof.account_memberships (
    user_id uuid NOT NULL,
    account_id uuid NOT NULL,
    property_id uuid NOT NULL,
    status text NOT NULL CHECK (status IN ('active', 'suspended', 'revoked')),
    PRIMARY KEY (user_id, account_id, property_id)
);

INSERT INTO g2_proof.account_memberships (user_id, account_id, property_id, status)
VALUES
    ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '11111111-1111-4111-8111-111111111111', 'active'),
    ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '22222222-2222-4222-8222-222222222222', 'suspended'),
    ('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '22222222-2222-4222-8222-222222222222', 'active')
ON CONFLICT (user_id, account_id, property_id) DO UPDATE
SET status = EXCLUDED.status;

CREATE OR REPLACE FUNCTION g2_proof.valid_principal_context()
RETURNS boolean
LANGUAGE sql
STABLE
AS $$
    SELECT security.valid_principal_context()
$$;

CREATE OR REPLACE FUNCTION g2_proof.current_principal_id()
RETURNS uuid
LANGUAGE sql
STABLE
AS $$
    SELECT CASE WHEN g2_proof.valid_principal_context()
                THEN lower(current_setting('app.principal_id', true))::uuid
           END
$$;

ALTER TABLE g2_proof.account_memberships ENABLE ROW LEVEL SECURITY;
ALTER TABLE g2_proof.account_memberships FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS account_membership_principal_scope ON g2_proof.account_memberships;
CREATE POLICY account_membership_principal_scope
    ON g2_proof.account_memberships
    FOR ALL
    TO dawineks_runtime
    USING (user_id = g2_proof.current_principal_id())
    WITH CHECK (user_id = g2_proof.current_principal_id());

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
                (SELECT key_material FROM security.context_keys WHERE is_active),
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
REVOKE ALL ON SCHEMA identity FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO dawineks_runtime;
GRANT USAGE ON SCHEMA identity TO dawineks_runtime;
REVOKE ALL ON FUNCTION g2_proof.current_account_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION g2_proof.current_property_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION g2_proof.valid_context() FROM PUBLIC;
REVOKE ALL ON FUNCTION g2_proof.current_principal_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION g2_proof.valid_principal_context() FROM PUBLIC;
GRANT USAGE ON SCHEMA g2_proof TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION g2_proof.valid_context() TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION g2_proof.current_account_id() TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION g2_proof.current_property_id() TO dawineks_runtime;
GRANT EXECUTE ON FUNCTION g2_proof.valid_principal_context() TO dawineks_runtime, dawineks_owner;
GRANT EXECUTE ON FUNCTION g2_proof.current_principal_id() TO dawineks_runtime;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE g2_proof.tenant_records TO dawineks_runtime;
GRANT SELECT ON TABLE g2_proof.account_memberships TO dawineks_runtime;
GRANT SELECT ON TABLE g2_proof.tenant_records_view TO dawineks_runtime;
