<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE dawineks_owner');

        DB::statement(<<<'SQL'
            ALTER TABLE identity.account_memberships
            ADD COLUMN role varchar(24) NOT NULL DEFAULT 'staff'
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE identity.account_memberships
            ADD CONSTRAINT identity_membership_role_check
            CHECK (role IN ('client_admin', 'staff'))
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE identity.users
            ADD COLUMN mfa_secret_ciphertext text NULL,
            ADD COLUMN mfa_recovery_code_hashes jsonb NOT NULL DEFAULT '[]'::jsonb,
            ADD COLUMN mfa_enabled_at timestamptz NULL,
            ADD CONSTRAINT identity_mfa_secret_enabled_check
            CHECK ((mfa_enabled_at IS NULL) = (mfa_secret_ciphertext IS NULL)),
            ADD CONSTRAINT identity_mfa_recovery_codes_array_check
            CHECK (jsonb_typeof(mfa_recovery_code_hashes) = 'array')
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
                mfa_secret_ciphertext text
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
                       client_user.mfa_secret_ciphertext
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
            RETURNS TABLE(id uuid, name varchar, email varchar, requires_mfa boolean, mfa_enabled boolean)
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
                       client_user.mfa_enabled_at IS NOT NULL AS mfa_enabled
                FROM identity.users AS client_user
                WHERE client_user.id = target_id
                  AND client_user.status = 'active'
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.save_client_mfa(
                target_user uuid,
                encrypted_secret text,
                recovery_hashes jsonb
            )
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity
            AS $function$
            DECLARE
                changed_rows integer;
            BEGIN
                IF NOT security.valid_principal_context()
                   OR security.current_principal_id() <> target_user
                   OR encrypted_secret IS NULL
                   OR length(encrypted_secret) > 2048 THEN
                    RETURN false;
                END IF;

                IF jsonb_typeof(recovery_hashes) IS DISTINCT FROM 'array' THEN
                    RETURN false;
                END IF;

                IF jsonb_array_length(recovery_hashes) NOT BETWEEN 1 AND 20 THEN
                    RETURN false;
                END IF;

                UPDATE identity.users
                SET mfa_secret_ciphertext = encrypted_secret,
                    mfa_recovery_code_hashes = recovery_hashes,
                    mfa_enabled_at = now(),
                    updated_at = now()
                WHERE id = target_user
                  AND status = 'active'
                  AND mfa_enabled_at IS NULL
                  AND EXISTS (
                      SELECT 1
                      FROM identity.account_memberships AS membership
                      WHERE membership.user_id = target_user
                        AND membership.status = 'active'
                        AND membership.role = 'client_admin'
                  );

                GET DIAGNOSTICS changed_rows = ROW_COUNT;

                RETURN changed_rows = 1;
            END;
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.consume_client_mfa_recovery_code(target_user uuid, candidate_code text)
            RETURNS boolean
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity, public
            AS $function$
            DECLARE
                matched_hash text;
                changed_rows integer;
            BEGIN
                IF NOT security.valid_principal_context()
                   OR security.current_principal_id() <> target_user
                   OR candidate_code IS NULL
                   OR length(candidate_code) > 32 THEN
                    RETURN false;
                END IF;

                SELECT code_hash.value
                INTO matched_hash
                FROM identity.users AS client_user
                CROSS JOIN LATERAL jsonb_array_elements_text(client_user.mfa_recovery_code_hashes) AS code_hash(value)
                WHERE client_user.id = target_user
                  AND client_user.status = 'active'
                  AND client_user.mfa_enabled_at IS NOT NULL
                  AND public.crypt(candidate_code, replace(code_hash.value, '$2y$', '$2a$'))
                      = replace(code_hash.value, '$2y$', '$2a$')
                LIMIT 1
                FOR UPDATE OF client_user;

                IF matched_hash IS NULL THEN
                    RETURN false;
                END IF;

                UPDATE identity.users AS client_user
                SET mfa_recovery_code_hashes = COALESCE(
                    (
                        SELECT jsonb_agg(code_hash.value)
                        FROM jsonb_array_elements_text(client_user.mfa_recovery_code_hashes) AS code_hash(value)
                        WHERE code_hash.value <> matched_hash
                    ),
                    '[]'::jsonb
                ),
                updated_at = now()
                WHERE client_user.id = target_user
                  AND client_user.status = 'active'
                  AND client_user.mfa_enabled_at IS NOT NULL;

                GET DIAGNOSTICS changed_rows = ROW_COUNT;

                RETURN changed_rows = 1;
            END;
            $function$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION security.authenticate_client_user(text, text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.load_client_user(uuid) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.save_client_mfa(uuid, text, jsonb) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.consume_client_mfa_recovery_code(uuid, text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION security.authenticate_client_user(text, text) TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.load_client_user(uuid) TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.save_client_mfa(uuid, text, jsonb) TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.consume_client_mfa_recovery_code(uuid, text) TO dawineks_runtime');
    }

    public function down(): void
    {
        throw new LogicException('Client MFA controls cannot be removed by an automatic migration rollback.');
    }
};
