<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE dawineks_owner');

        DB::statement(<<<'SQL'
            ALTER TABLE identity.users
            ADD COLUMN auth_version integer NOT NULL DEFAULT 1,
            ADD CONSTRAINT identity_users_auth_version_check CHECK (auth_version > 0)
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE security.client_password_reset_tokens (
                user_id uuid PRIMARY KEY REFERENCES identity.users(id) ON DELETE CASCADE,
                token_digest bytea NOT NULL CHECK (octet_length(token_digest) = 32),
                expires_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('ALTER TABLE security.client_password_reset_tokens ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE security.client_password_reset_tokens FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY client_password_reset_owner_scope
            ON security.client_password_reset_tokens
            FOR ALL TO dawineks_owner USING (true) WITH CHECK (true)
            SQL);

        DB::statement('DROP FUNCTION security.authenticate_client_user(text, text)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION security.authenticate_client_user(target_email text, target_password text)
            RETURNS TABLE(
                id uuid,
                name varchar,
                email varchar,
                requires_mfa boolean,
                mfa_enabled boolean,
                mfa_secret_ciphertext text,
                auth_version integer
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity, public
            AS $function$
                SELECT client_user.id,
                       client_user.name,
                       client_user.email,
                       EXISTS (
                           SELECT 1
                           FROM identity.account_memberships AS membership
                           WHERE membership.user_id = client_user.id
                             AND membership.status = 'active'
                             AND membership.role = 'client_admin'
                       ) AS requires_mfa,
                       client_user.mfa_enabled_at IS NOT NULL AS mfa_enabled,
                       client_user.mfa_secret_ciphertext,
                       client_user.auth_version
                FROM identity.users AS client_user
                WHERE lower(client_user.email) = lower(btrim(target_email))
                  AND client_user.status = 'active'
                  AND octet_length(target_password) <= 72
                  AND client_user.password ~ '^\$2[ay]\$[0-9]{2}\$'
                  AND public.crypt(
                      target_password,
                      replace(client_user.password, '$2y$', '$2a$')
                  ) = replace(client_user.password, '$2y$', '$2a$')
            $function$
            SQL);

        DB::statement('DROP FUNCTION security.load_client_user(uuid)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION security.load_client_user(target_id uuid)
            RETURNS TABLE(
                id uuid,
                name varchar,
                email varchar,
                requires_mfa boolean,
                mfa_enabled boolean,
                auth_version integer
            )
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity
            AS $function$
                SELECT client_user.id,
                       client_user.name,
                       client_user.email,
                       EXISTS (
                           SELECT 1
                           FROM identity.account_memberships AS membership
                           WHERE membership.user_id = client_user.id
                             AND membership.status = 'active'
                             AND membership.role = 'client_admin'
                       ) AS requires_mfa,
                       client_user.mfa_enabled_at IS NOT NULL AS mfa_enabled,
                       client_user.auth_version
                FROM identity.users AS client_user
                WHERE client_user.id = target_id
                  AND client_user.status = 'active'
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.issue_client_password_reset(target_email text)
            RETURNS TABLE(email varchar, token text)
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity, public
            AS $function$
            DECLARE
                client_user_id uuid;
                client_email varchar;
                reset_token text;
                changed_rows integer;
            BEGIN
                IF target_email IS NULL OR length(target_email) > 254 THEN
                    RETURN;
                END IF;

                SELECT client_user.id, client_user.email
                INTO client_user_id, client_email
                FROM identity.users AS client_user
                WHERE lower(client_user.email) = lower(btrim(target_email))
                  AND client_user.status = 'active';

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                reset_token := encode(public.gen_random_bytes(32), 'hex');

                INSERT INTO security.client_password_reset_tokens (user_id, token_digest, expires_at, created_at)
                VALUES (
                    client_user_id,
                    public.digest(convert_to(reset_token, 'UTF8'), 'sha256'),
                    now() + interval '15 minutes',
                    now()
                )
                ON CONFLICT (user_id) DO UPDATE
                SET token_digest = EXCLUDED.token_digest,
                    expires_at = EXCLUDED.expires_at,
                    created_at = EXCLUDED.created_at
                WHERE security.client_password_reset_tokens.created_at <= now() - interval '1 minute';

                GET DIAGNOSTICS changed_rows = ROW_COUNT;

                IF changed_rows <> 1 THEN
                    RETURN;
                END IF;

                RETURN QUERY SELECT client_email, reset_token;
            END;
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.complete_client_password_reset(
                target_email text,
                reset_token text,
                new_password_hash text
            )
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity, public
            AS $function$
            DECLARE
                target_user_id uuid;
                changed_rows integer;
            BEGIN
                IF target_email IS NULL
                   OR length(target_email) > 254
                   OR reset_token IS NULL
                   OR reset_token !~ '^[0-9a-fA-F]{64}$'
                   OR new_password_hash IS NULL
                   OR length(new_password_hash) > 72
                   OR new_password_hash !~ '^\$2[ay]\$[0-9]{2}\$' THEN
                    RETURN false;
                END IF;

                SELECT reset_row.user_id
                INTO target_user_id
                FROM security.client_password_reset_tokens AS reset_row
                JOIN identity.users AS client_user ON client_user.id = reset_row.user_id
                WHERE lower(client_user.email) = lower(btrim(target_email))
                  AND client_user.status = 'active'
                  AND reset_row.token_digest = public.digest(convert_to(reset_token, 'UTF8'), 'sha256')
                  AND reset_row.expires_at > now()
                FOR UPDATE OF reset_row;

                IF NOT FOUND THEN
                    RETURN false;
                END IF;

                UPDATE identity.users
                SET password = new_password_hash,
                    auth_version = auth_version + 1,
                    updated_at = now()
                WHERE id = target_user_id
                  AND status = 'active';

                GET DIAGNOSTICS changed_rows = ROW_COUNT;

                IF changed_rows <> 1 THEN
                    RETURN false;
                END IF;

                DELETE FROM security.client_password_reset_tokens
                WHERE user_id = target_user_id;

                RETURN true;
            END;
            $function$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION security.authenticate_client_user(text, text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.load_client_user(uuid) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.issue_client_password_reset(text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.complete_client_password_reset(text, text, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION security.authenticate_client_user(text, text) TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.load_client_user(uuid) TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.issue_client_password_reset(text) TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.complete_client_password_reset(text, text, text) TO dawineks_runtime');
    }

    public function down(): void
    {
        throw new LogicException('Client password-reset controls cannot be removed by an automatic migration rollback.');
    }
};
