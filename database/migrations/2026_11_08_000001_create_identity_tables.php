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
    }

    public function down(): void
    {
        Schema::dropIfExists('identity.account_memberships');
        Schema::dropIfExists('identity.users');
    }
};
