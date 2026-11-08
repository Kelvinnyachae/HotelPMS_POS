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
