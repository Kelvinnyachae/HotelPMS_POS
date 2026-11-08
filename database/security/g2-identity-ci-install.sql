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
