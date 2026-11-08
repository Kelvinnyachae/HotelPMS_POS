-- Disposable CI-only identity rows. Security, ownership and grants are installed
-- by the versioned identity migration and identity-security-bootstrap.sql.

INSERT INTO identity.users (id, name, email, password, created_at, updated_at)
VALUES
    ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'G2 Principal A', 'g2-a@example.invalid', 'ci-only', now(), now()),
    ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'G2 Principal B', 'g2-b@example.invalid', 'ci-only', now(), now()),
    ('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'G2 Principal C', 'g2-c@example.invalid', 'ci-only', now(), now())
ON CONFLICT (id) DO NOTHING;

INSERT INTO identity.account_memberships (user_id, account_id, property_id, status)
VALUES
    ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '11111111-1111-4111-8111-111111111111', 'active'),
    ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '22222222-2222-4222-8222-222222222222', 'suspended'),
    ('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '22222222-2222-4222-8222-222222222222', 'active')
ON CONFLICT (user_id, account_id, property_id) DO UPDATE SET status = EXCLUDED.status;

-- CI-only proof helper: bind the test tenant policy to the production identity
-- membership table. Production tenant policies will use the security schema.
CREATE OR REPLACE FUNCTION g2_proof.has_active_membership(target_account uuid, target_property uuid)
RETURNS boolean
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, g2_proof, identity
AS $$
    SELECT COALESCE(
        security.valid_principal_context()
        AND EXISTS (
            SELECT 1
            FROM identity.account_memberships AS membership
            WHERE membership.user_id = lower(current_setting('app.principal_id', true))::uuid
              AND membership.account_id = target_account
              AND membership.property_id = target_property
              AND membership.status = 'active'
        ),
        false
    )
$$;
ALTER FUNCTION g2_proof.has_active_membership(uuid, uuid) OWNER TO dawineks_owner;
REVOKE ALL ON FUNCTION g2_proof.has_active_membership(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION g2_proof.has_active_membership(uuid, uuid) TO dawineks_runtime;

CREATE OR REPLACE FUNCTION g2_proof.valid_context()
RETURNS boolean
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, g2_proof
AS $$
    SELECT COALESCE(
        context.account_id ~ '^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
        AND context.property_id ~ '^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
        AND context.signature ~ '^[0-9a-fA-F]{64}$'
        AND security.valid_principal_context()
        AND encode(
            public.hmac(
                convert_to(lower(current_setting('app.principal_id', true)) || ':' || lower(context.account_id) || ':' || lower(context.property_id) || ':' || txid_current()::text, 'UTF8'),
                (SELECT key_material FROM security.context_keys WHERE is_active),
                'sha256'
            ),
            'hex'
        ) = lower(context.signature)
        AND CASE
            WHEN context.account_id ~ '^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
             AND context.property_id ~ '^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$'
            THEN g2_proof.has_active_membership(lower(context.account_id)::uuid, lower(context.property_id)::uuid)
            ELSE false
        END,
        false
    )
    FROM (
        SELECT current_setting('app.account_id', true) AS account_id,
               current_setting('app.property_id', true) AS property_id,
               current_setting('app.context_signature', true) AS signature
    ) AS context
$$;
