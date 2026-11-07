<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplyTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

final class PostgresTenantIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => env('G2_DB_DATABASE', 'dawineks_hospitality_os'),
        ]);

        DB::purge('pgsql');
    }

    public function test_runtime_is_a_non_owner_without_rls_bypass(): void
    {
        $role = DB::selectOne(<<<'SQL'
            SELECT roles.rolsuper::text AS is_superuser,
                   roles.rolcreatedb::text AS can_create_database,
                   roles.rolcreaterole::text AS can_create_roles,
                   roles.rolbypassrls::text AS can_bypass_rls,
                   EXISTS (SELECT 1 FROM pg_auth_members WHERE member = roles.oid)::text AS has_role_memberships,
                   has_database_privilege(current_user, current_database(), 'CONNECT')::text AS can_connect_database,
                   has_schema_privilege(current_user, 'public', 'CREATE')::text AS can_create_public_schema,
                   has_schema_privilege(current_user, 'tenant', 'USAGE')::text AS can_use_tenant_schema,
                   has_schema_privilege(current_user, 'tenant', 'CREATE')::text AS can_create_tenant_schema,
                   has_schema_privilege(current_user, 'g2_proof', 'USAGE')::text AS can_use_proof_schema,
                   has_schema_privilege(current_user, 'g2_proof', 'CREATE')::text AS can_create_proof_schema,
                   databases.datdba::regrole::text AS database_owner,
                   (roles.rolname = databases.datdba::regrole::text)::text AS is_database_owner
            FROM pg_roles AS roles
            JOIN pg_database AS databases ON databases.datname = current_database()
            WHERE roles.rolname = current_user
            SQL);

        $this->assertNotNull($role);
        $this->assertSame('false', $role->is_superuser);
        $this->assertSame('false', $role->can_create_database);
        $this->assertSame('false', $role->can_create_roles);
        $this->assertSame('false', $role->can_bypass_rls);
        $this->assertSame('false', $role->has_role_memberships);
        $this->assertSame('true', $role->can_connect_database);
        $this->assertSame('false', $role->can_create_public_schema);
        $this->assertSame('false', $role->can_use_tenant_schema);
        $this->assertSame('false', $role->can_create_tenant_schema);
        $this->assertSame('true', $role->can_use_proof_schema);
        $this->assertSame('false', $role->can_create_proof_schema);
        $this->assertSame('false', $role->is_database_owner);
        $this->assertSame('dawineks_owner', $role->database_owner);
    }

    public function test_proof_table_is_owned_separately_and_forces_rls(): void
    {
        $table = DB::selectOne(<<<'SQL'
            SELECT table_class.relowner::regrole::text AS table_owner,
                   table_class.relrowsecurity::text AS rls_enabled,
                   table_class.relforcerowsecurity::text AS rls_forced,
                   EXISTS (
                       SELECT 1
                       FROM pg_policies AS policies
                       WHERE policies.schemaname = 'g2_proof'
                         AND policies.tablename = 'tenant_records'
                         AND policies.qual IS NOT NULL
                         AND policies.with_check IS NOT NULL
                   )::text AS has_scoped_policy
            FROM pg_class AS table_class
            JOIN pg_namespace AS table_schema ON table_schema.oid = table_class.relnamespace
            WHERE table_schema.nspname = 'g2_proof'
              AND table_class.relname = 'tenant_records'
            SQL);

        $this->assertNotNull($table);
        $this->assertSame('dawineks_owner', $table->table_owner);
        $this->assertSame('true', $table->rls_enabled);
        $this->assertSame('true', $table->rls_forced);
        $this->assertSame('true', $table->has_scoped_policy);
    }

    public function test_missing_context_fails_closed_for_reads_and_writes(): void
    {
        $this->assertSame(0, DB::table('g2_proof.tenant_records')->count());

        $this->expectException(QueryException::class);
        DB::table('g2_proof.tenant_records')->insert([
            'record_id' => Str::uuid()->toString(),
            'account_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'property_id' => '11111111-1111-4111-8111-111111111111',
            'payload' => 'must be denied without tenant context',
        ]);
    }

    public function test_runtime_cannot_forge_valid_context_with_custom_settings(): void
    {
        DB::beginTransaction();
        try {
            DB::select("select set_config('app.account_id', ?, true)", ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
            DB::select("select set_config('app.property_id', ?, true)", ['11111111-1111-4111-8111-111111111111']);
            DB::select("select set_config('app.context_signature', ?, true)", [str_repeat('0', 64)]);

            $this->assertSame(0, DB::table('g2_proof.tenant_records')->count());

            try {
                DB::table('g2_proof.context_keys')->count();
                $this->fail('The runtime login must not read the signing key.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_account_and_property_context_is_isolated_on_tables_and_security_invoker_views(): void
    {
        $accountA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyA = '11111111-1111-4111-8111-111111111111';
        $accountB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $propertyB = '22222222-2222-4222-8222-222222222222';
        $recordA = Str::uuid()->toString();
        $recordAOtherProperty = Str::uuid()->toString();
        $recordB = Str::uuid()->toString();
        $context = app(TenantContext::class);

        $context->within($accountA, $propertyA, function () use ($accountA, $propertyA, $recordA): void {
            DB::table('g2_proof.tenant_records')->insert([
                'record_id' => $recordA,
                'account_id' => $accountA,
                'property_id' => $propertyA,
                'payload' => 'account A property A',
            ]);
        });

        $context->within($accountB, $propertyB, function () use ($accountB, $propertyB, $recordB): void {
            DB::table('g2_proof.tenant_records')->insert([
                'record_id' => $recordB,
                'account_id' => $accountB,
                'property_id' => $propertyB,
                'payload' => 'account B property B',
            ]);
        });

        $context->within($accountA, $propertyB, function () use ($accountA, $propertyB, $recordAOtherProperty): void {
            DB::table('g2_proof.tenant_records')->insert([
                'record_id' => $recordAOtherProperty,
                'account_id' => $accountA,
                'property_id' => $propertyB,
                'payload' => 'account A property B',
            ]);
        });

        $visibleA = $context->within($accountA, $propertyA, fn (): array => DB::table('g2_proof.tenant_records')
            ->whereIn('record_id', [$recordA, $recordAOtherProperty, $recordB])
            ->pluck('payload')
            ->all());
        $visibleThroughView = $context->within($accountA, $propertyA, fn (): array => DB::table('g2_proof.tenant_records_view')
            ->whereIn('record_id', [$recordA, $recordAOtherProperty, $recordB])
            ->pluck('payload')
            ->all());
        $visibleOtherProperty = $context->within($accountA, $propertyB, fn (): array => DB::table('g2_proof.tenant_records')
            ->whereIn('record_id', [$recordA, $recordAOtherProperty, $recordB])
            ->pluck('payload')
            ->all());

        $this->assertSame(['account A property A'], $visibleA);
        $this->assertSame(['account A property A'], $visibleThroughView);
        $this->assertSame(['account A property B'], $visibleOtherProperty);
    }

    public function test_transaction_local_context_is_cleared_after_success_and_failure(): void
    {
        $context = app(TenantContext::class);
        $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyId = '11111111-1111-4111-8111-111111111111';
        $backendPid = DB::selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid;

        $context->within($accountId, $propertyId, fn (): string => 'completed');
        $this->assertContextCleared();
        $this->assertSame($backendPid, DB::selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid);

        $rolledBackRecordId = Str::uuid()->toString();

        try {
            $context->within($accountId, $propertyId, function () use ($accountId, $propertyId, $rolledBackRecordId): never {
                DB::table('g2_proof.tenant_records')->insert([
                    'record_id' => $rolledBackRecordId,
                    'account_id' => $accountId,
                    'property_id' => $propertyId,
                    'payload' => 'must roll back after failure',
                ]);

                throw new \RuntimeException('simulated request failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated request failure', $exception->getMessage());
        }

        $this->assertContextCleared();
        $this->assertSame($backendPid, DB::selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid);
        $visibleAfterFailure = $context->within($accountId, $propertyId, fn (): int => DB::table('g2_proof.tenant_records')
            ->where('record_id', $rolledBackRecordId)
            ->count());
        $this->assertSame(0, $visibleAfterFailure);
    }

    public function test_connection_is_purged_when_postgres_terminates_it_during_tenant_work(): void
    {
        $adminUsername = getenv('G2_ADMIN_DB_USERNAME');
        $adminPassword = getenv('G2_ADMIN_DB_PASSWORD');

        if (! is_string($adminUsername) || $adminUsername === '' || ! is_string($adminPassword) || $adminPassword === '') {
            $this->markTestSkipped('A disposable PostgreSQL administrator connection is required to terminate the test session.');
        }

        $connection = DB::connection('pgsql');
        $backendPid = (int) $connection->selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid;
        $adminConnection = new PDO(
            'pgsql:host='.getenv('PGHOST').' port='.getenv('PGPORT').' dbname=postgres',
            $adminUsername,
            $adminPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );

        try {
            app(TenantContext::class)->within(
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                '11111111-1111-4111-8111-111111111111',
                function () use ($adminConnection, $backendPid): string {
                    $termination = $adminConnection->prepare('SELECT pg_terminate_backend(:backend_pid)');
                    $termination->execute(['backend_pid' => $backendPid]);
                    $terminated = $termination->fetchColumn();
                    $this->assertTrue(in_array($terminated, [true, 1, '1', 't'], true));

                    return 'connection should be gone before transaction commit';
                },
            );
            $this->fail('The transaction must fail after PostgreSQL terminates its connection.');
        } catch (Throwable) {
            // TenantContext must purge the dead Laravel connection after rollback also fails.
        }

        $replacementConnection = DB::connection('pgsql');
        $replacementPid = (int) $replacementConnection->selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid;

        $this->assertNotSame($backendPid, $replacementPid);
        $this->assertSame(0, $replacementConnection->transactionLevel());
        $this->assertContextCleared();
    }

    public function test_client_supplied_tenant_headers_do_not_create_server_context(): void
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_ACCOUNT_ID' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'HTTP_X_PROPERTY_ID' => '11111111-1111-4111-8111-111111111111',
        ]);
        $request->setUserResolver(fn (): object => new \stdClass);
        $middleware = app(ApplyTenantContext::class);

        try {
            $middleware->handle($request, fn (Request $request): Response => new Response);
            $this->fail('Client headers must not be accepted as resolved tenant membership.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_middleware_wraps_downstream_work_with_server_resolved_context_and_clears_it(): void
    {
        $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyId = '11111111-1111-4111-8111-111111111111';
        $middleware = app(ApplyTenantContext::class);
        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn (): object => new \stdClass);
        $request->attributes->set('tenant.account_id', $accountId);
        $request->attributes->set('tenant.property_id', $propertyId);

        $response = $middleware->handle($request, function (Request $request) use ($accountId, $propertyId): Response {
            $this->assertSame(1, DB::connection()->transactionLevel());
            $context = DB::selectOne("SELECT current_setting('app.account_id', true) AS account_id, current_setting('app.property_id', true) AS property_id, length(current_setting('app.context_signature', true)) AS signature_length");
            $this->assertSame($accountId, $context->account_id);
            $this->assertSame($propertyId, $context->property_id);
            $this->assertSame(64, $context->signature_length);

            return new Response('tenant-scoped response');
        });

        $this->assertSame('tenant-scoped response', $response->getContent());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertContextCleared();

        $failingRequest = Request::create('/', 'GET');
        $failingRequest->setUserResolver(fn (): object => new \stdClass);
        $failingRequest->attributes->set('tenant.account_id', $accountId);
        $failingRequest->attributes->set('tenant.property_id', $propertyId);

        try {
            $middleware->handle($failingRequest, function (): never {
                throw new \RuntimeException('simulated downstream request failure');
            });
            $this->fail('A downstream exception must be rethrown after tenant context cleanup.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated downstream request failure', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertContextCleared();
    }

    public function test_tenant_middleware_rejects_requests_without_an_authenticated_user(): void
    {
        $request = Request::create('/', 'GET');

        try {
            app(ApplyTenantContext::class)->handle($request, fn (Request $request): Response => new Response);
            $this->fail('Tenant middleware must reject unauthenticated requests.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    private function assertContextCleared(): void
    {
        $context = DB::selectOne("SELECT current_setting('app.account_id', true) AS account_id, current_setting('app.property_id', true) AS property_id");

        $this->assertTrue($context->account_id === null || $context->account_id === '');
        $this->assertTrue($context->property_id === null || $context->property_id === '');
    }
}
