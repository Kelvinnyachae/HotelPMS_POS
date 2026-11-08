<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expose only verified identity data to the web runtime. Password hashes
     * remain unreadable to dawineks_runtime.
     */
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE dawineks_owner');

        DB::statement(<<<'SQL'
            ALTER TABLE identity.users
            ADD COLUMN status varchar(16) NOT NULL DEFAULT 'active'
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE identity.users
            ADD CONSTRAINT identity_users_status_check
            CHECK (status IN ('active', 'suspended', 'disabled'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION security.has_active_membership(target_account uuid, target_property uuid)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity
            AS $function$
                SELECT COALESCE(
                    security.valid_principal_context()
                    AND EXISTS (
                        SELECT 1
                        FROM identity.account_memberships AS membership
                        JOIN identity.users AS client_user ON client_user.id = membership.user_id
                        WHERE membership.user_id = security.current_principal_id()
                          AND membership.account_id = target_account
                          AND membership.property_id = target_property
                          AND membership.status = 'active'
                          AND client_user.status = 'active'
                    ),
                    false
                )
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.authenticate_client_user(target_email text, target_password text)
            RETURNS TABLE(id uuid, name varchar, email varchar)
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity, public
            AS $function$
                SELECT client_user.id, client_user.name, client_user.email
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

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.load_client_user(target_id uuid)
            RETURNS TABLE(id uuid, name varchar, email varchar)
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity
            AS $function$
                SELECT client_user.id, client_user.name, client_user.email
                FROM identity.users AS client_user
                WHERE client_user.id = target_id
                  AND client_user.status = 'active'
            $function$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION security.authenticate_client_user(text, text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.load_client_user(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION security.authenticate_client_user(text, text) TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.load_client_user(uuid) TO dawineks_runtime');
        DB::statement('GRANT SELECT ON TABLE identity.users TO dawineks_owner');
    }

    public function down(): void
    {
        throw new LogicException('Client authentication security cannot be removed by an automatic migration rollback.');
    }
};
