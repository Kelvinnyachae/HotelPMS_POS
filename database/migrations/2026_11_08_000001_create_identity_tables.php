<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identity tables are control-plane records, not tenant business data.
     * The identity schema must be provisioned and owned by the database owner
     * before the separately credentialed migration role runs this migration.
     */
    public function up(): void
    {
        DB::statement('SET LOCAL ROLE dawineks_owner');

        Schema::create('identity.users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('email', 254)->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestampsTz();
        });

        Schema::create('identity.account_memberships', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('account_id');
            $table->uuid('property_id');
            $table->string('status', 16)->default('active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->nullable();
            $table->primary(['user_id', 'account_id', 'property_id']);
            $table->foreign('user_id')->references('id')->on('identity.users')->cascadeOnDelete();
            $table->index(['account_id', 'property_id', 'status'], 'identity_membership_scope_status_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE identity.account_memberships
            ADD CONSTRAINT identity_membership_status_check
            CHECK (status IN ('active', 'suspended', 'revoked'))
            SQL);

        DB::statement('CREATE UNIQUE INDEX identity_users_email_lower_unique ON identity.users (lower(email))');

        DB::statement('ALTER TABLE identity.users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE identity.users FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY identity_users_owner_scope ON identity.users
            FOR ALL TO dawineks_owner USING (true) WITH CHECK (true)
            SQL);

        DB::statement('ALTER TABLE identity.account_memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE identity.account_memberships FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY identity_membership_principal_scope ON identity.account_memberships
            FOR SELECT TO dawineks_runtime USING (user_id = security.current_principal_id())
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY identity_membership_owner_scope ON identity.account_memberships
            FOR ALL TO dawineks_owner USING (true) WITH CHECK (true)
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.has_active_membership(target_account uuid, target_property uuid)
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
                        WHERE membership.user_id = security.current_principal_id()
                          AND membership.account_id = target_account
                          AND membership.property_id = target_property
                          AND membership.status = 'active'
                    ),
                    false
                )
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.valid_tenant_context()
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = pg_catalog, security, identity
            AS $function$
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
                        THEN security.has_active_membership(lower(context.account_id)::uuid, lower(context.property_id)::uuid)
                        ELSE false
                    END,
                    false
                )
                FROM (
                    SELECT current_setting('app.account_id', true) AS account_id,
                           current_setting('app.property_id', true) AS property_id,
                           current_setting('app.context_signature', true) AS signature
                ) AS context
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.current_account_id()
            RETURNS uuid
            LANGUAGE sql
            STABLE
            AS $function$
                SELECT CASE WHEN security.valid_tenant_context()
                            THEN lower(current_setting('app.account_id', true))::uuid
                       END
            $function$
            SQL);

        DB::statement(<<<'SQL'
            CREATE FUNCTION security.current_property_id()
            RETURNS uuid
            LANGUAGE sql
            STABLE
            AS $function$
                SELECT CASE WHEN security.valid_tenant_context()
                            THEN lower(current_setting('app.property_id', true))::uuid
                       END
            $function$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION security.has_active_membership(uuid, uuid) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.valid_tenant_context() FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.current_account_id() FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION security.current_property_id() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION security.valid_tenant_context() TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.current_account_id() TO dawineks_runtime');
        DB::statement('GRANT EXECUTE ON FUNCTION security.current_property_id() TO dawineks_runtime');

        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA identity FROM PUBLIC, dawineks_runtime');
        DB::statement('GRANT USAGE ON SCHEMA identity TO dawineks_runtime');
        DB::statement('GRANT SELECT ON TABLE identity.account_memberships TO dawineks_runtime');
        DB::statement('GRANT SELECT ON TABLE identity.account_memberships TO dawineks_owner');
    }

    public function down(): void
    {
        throw new LogicException('Identity tables cannot be removed by an automatic migration rollback.');
    }
};
