<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplyTenantContext;
use App\Mail\ClientPasswordResetMail;
use App\Models\User;
use App\Providers\ClientTotp;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMembershipResolver;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
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
            'tenancy.membership_table' => env('TENANT_MEMBERSHIP_TABLE', 'g2_proof.account_memberships'),
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
                DB::table('security.context_keys')->count();
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
        $recordB = Str::uuid()->toString();

        $this->withinAuthorizedMembership('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $accountA, $propertyA, function () use ($accountA, $propertyA, $recordA): void {
            DB::table('g2_proof.tenant_records')->insert([
                'record_id' => $recordA,
                'account_id' => $accountA,
                'property_id' => $propertyA,
                'payload' => 'account A property A',
            ]);
        });

        $this->withinAuthorizedMembership('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $accountB, $propertyB, function () use ($accountB, $propertyB, $recordB): void {
            DB::table('g2_proof.tenant_records')->insert([
                'record_id' => $recordB,
                'account_id' => $accountB,
                'property_id' => $propertyB,
                'payload' => 'account B property B',
            ]);
        });

        $visibleA = $this->withinAuthorizedMembership('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $accountA, $propertyA, fn (): array => DB::table('g2_proof.tenant_records')
            ->whereIn('record_id', [$recordA, $recordB])
            ->pluck('payload')
            ->all());
        $visibleThroughView = $this->withinAuthorizedMembership('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $accountA, $propertyA, fn (): array => DB::table('g2_proof.tenant_records_view')
            ->whereIn('record_id', [$recordA, $recordB])
            ->pluck('payload')
            ->all());
        $visibleOtherProperty = $this->withinAuthorizedMembership('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $accountB, $propertyB, fn (): array => DB::table('g2_proof.tenant_records')
            ->whereIn('record_id', [$recordA, $recordB])
            ->pluck('payload')
            ->all());

        $this->assertSame(['account A property A'], $visibleA);
        $this->assertSame(['account A property A'], $visibleThroughView);
        $this->assertSame(['account B property B'], $visibleOtherProperty);
    }

    public function test_transaction_local_context_is_cleared_after_success_and_failure(): void
    {
        $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyId = '11111111-1111-4111-8111-111111111111';
        $backendPid = DB::selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid;

        $this->withinAuthorizedMembership('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $accountId, $propertyId, fn (): string => 'completed');
        $this->assertContextCleared();
        $this->assertSame($backendPid, DB::selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid);

        $rolledBackRecordId = Str::uuid()->toString();

        try {
            $this->withinAuthorizedMembership('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $accountId, $propertyId, function () use ($accountId, $propertyId, $rolledBackRecordId): never {
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
        $visibleAfterFailure = $this->withinAuthorizedMembership('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $accountId, $propertyId, fn (): int => DB::table('g2_proof.tenant_records')
            ->where('record_id', $rolledBackRecordId)
            ->count());
        $this->assertSame(0, $visibleAfterFailure);
    }

    public function test_session_level_context_cannot_authorize_a_later_transaction(): void
    {
        $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyId = '11111111-1111-4111-8111-111111111111';
        $contextKey = hex2bin((string) config('tenancy.context_key_hex'));

        if ($contextKey === false) {
            $this->fail('The G2 signing key must be valid for this test.');
        }

        DB::beginTransaction();
        try {
            $transactionId = (string) DB::selectOne('SELECT txid_current() AS transaction_id')->transaction_id;
            $signature = hash_hmac('sha256', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:'.$accountId.':'.$propertyId.':'.$transactionId, $contextKey);
            DB::select("SELECT set_config('app.principal_id', ?, false)", ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
            DB::select("SELECT set_config('app.principal_signature', ?, false)", [hash_hmac('sha256', 'principal:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:'.$transactionId, $contextKey)]);
            DB::select("SELECT set_config('app.account_id', ?, false)", [$accountId]);
            DB::select("SELECT set_config('app.property_id', ?, false)", [$propertyId]);
            DB::select("SELECT set_config('app.context_signature', ?, false)", [$signature]);
            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }

        try {
            $this->assertSame(0, DB::table('g2_proof.tenant_records')->count());
        } finally {
            DB::statement('RESET app.principal_id');
            DB::statement('RESET app.principal_signature');
            DB::statement('RESET app.account_id');
            DB::statement('RESET app.property_id');
            DB::statement('RESET app.context_signature');
        }
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
            $this->withinAuthorizedMembership(
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                '11111111-1111-4111-8111-111111111111',
                function () use ($adminConnection, $backendPid): string {
                    $termination = $adminConnection->prepare('SELECT pg_terminate_backend(:backend_pid)');
                    $termination->execute(['backend_pid' => $backendPid]);
                    $terminated = $termination->fetchColumn();
                    $this->assertTrue(in_array($terminated, [true, 1, '1', 't'], true));

                    $deadline = microtime(true) + 5;
                    do {
                        $activity = $adminConnection->prepare('SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE pid = :backend_pid)');
                        $activity->execute(['backend_pid' => $backendPid]);
                        $backendStillActive = $activity->fetchColumn();
                        if (! in_array($backendStillActive, [true, 1, '1', 't'], true)) {
                            break;
                        }

                        usleep(50000);
                    } while (microtime(true) < $deadline);

                    $this->assertFalse(in_array($backendStillActive, [true, 1, '1', 't'], true));

                    return 'connection should be gone before transaction commit';
                },
            );
            $this->fail('The transaction must fail after PostgreSQL terminates its connection.');
        } catch (Throwable) {
            // TenantContext must purge the dead Laravel connection after rollback also fails.
        }

        $replacementConnection = DB::connection('pgsql');
        $replacementPid = (int) $replacementConnection->selectOne('SELECT pg_backend_pid() AS backend_pid')->backend_pid;

        $this->assertNotSame(spl_object_id($connection), spl_object_id($replacementConnection));
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
        $request->setUserResolver(fn (): User => $this->user('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        $middleware = app(ApplyTenantContext::class);

        try {
            $middleware->handle($request, fn (Request $request): Response => new Response);
            $this->fail('Client headers must not be accepted as resolved tenant membership.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertContextCleared();
    }

    public function test_middleware_wraps_downstream_work_with_server_resolved_context_and_clears_it(): void
    {
        $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyId = '11111111-1111-4111-8111-111111111111';
        $middleware = app(ApplyTenantContext::class);
        $request = Request::create('/', 'GET', ['account_id' => $accountId, 'property_id' => $propertyId]);
        $request->setUserResolver(fn (): User => $this->user($accountId));
        $this->assertSame($accountId, $request->input('account_id'));
        $this->assertSame($propertyId, $request->input('property_id'));
        $this->assertSame($accountId, $request->user()->getAuthIdentifier());

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

        $failingRequest = Request::create('/', 'GET', ['account_id' => $accountId, 'property_id' => $propertyId]);
        $failingRequest->setUserResolver(fn (): User => $this->user($accountId));

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

    public function test_real_http_error_response_rolls_back_tenant_writes(): void
    {
        $recordId = Str::uuid()->toString();
        $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyId = '11111111-1111-4111-8111-111111111111';

        Route::post('/__g2/error-after-write', function () use ($recordId, $accountId, $propertyId): never {
            DB::table('g2_proof.tenant_records')->insert([
                'record_id' => $recordId,
                'account_id' => $accountId,
                'property_id' => $propertyId,
                'payload' => 'must roll back when Laravel renders the exception',
            ]);

            abort(500, 'simulated HTTP action failure');
        })->middleware('tenant.context');

        $response = $this->actingAs($this->user($accountId))
            ->post('/__g2/error-after-write', ['account_id' => $accountId, 'property_id' => $propertyId]);

        $response->assertInternalServerError();
        $remainingRows = $this->withinAuthorizedMembership($accountId, $accountId, $propertyId, fn (): int => DB::table('g2_proof.tenant_records')
            ->where('record_id', $recordId)
            ->count());

        $this->assertSame(0, $remainingRows);
        $this->assertContextCleared();
    }

    public function test_workspace_route_runs_in_resolved_tenant_context_for_authenticated_user(): void
    {
        $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $propertyId = '11111111-1111-4111-8111-111111111111';

        $this->actingAs($this->user($accountId))
            ->withSession(['auth.auth_version' => 1])
            ->get('/workspace?account_id='.$accountId.'&property_id='.$propertyId)
            ->assertOk()
            ->assertSee('Client Workspace');

        $this->assertContextCleared();
    }

    public function test_client_sign_in_verifies_credentials_without_reading_password_hashes(): void
    {
        $this->post('/login', [
            'email' => 'G2-A@EXAMPLE.INVALID',
            'password' => 'G2 CI fixture password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertSame(0, DB::table('information_schema.role_table_grants')
            ->where('table_schema', 'identity')
            ->where('table_name', 'users')
            ->where('grantee', 'dawineks_runtime')
            ->where('privilege_type', 'SELECT')
            ->count());

        Auth::forgetGuards();
        $this->get('/workspace?account_id=aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa&property_id=11111111-1111-4111-8111-111111111111')
            ->assertOk()
            ->assertSee('Client Workspace');
        $this->assertContextCleared();
    }

    public function test_client_sign_in_rejects_an_incorrect_password(): void
    {
        $this->post('/login', [
            'email' => 'g2-a@example.invalid',
            'password' => 'incorrect fixture password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_client_administrator_must_enroll_mfa_before_workspace_access(): void
    {
        $this->post('/login', [
            'email' => 'g2-admin@example.invalid',
            'password' => 'G2 CI fixture password',
        ])->assertRedirect(route('mfa.setup'));

        $this->assertGuest();
        $this->get('/workspace/select')->assertRedirect(route('login'));
        $this->get('/workspace?account_id=aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa&property_id=11111111-1111-4111-8111-111111111111')
            ->assertRedirect(route('login'));

        $enrollment = $this->completeClientAdminMfaEnrollment(
            'g2-admin@example.invalid',
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        );

        $this->get('/workspace/select')
            ->assertOk()
            ->assertSee('Choose your workspace')
            ->assertSee('11111111-1111-4111-8111-111111111111');
        $this->get('/workspace?account_id=aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa&property_id=11111111-1111-4111-8111-111111111111')
            ->assertOk()
            ->assertSee('Client Workspace');

        $mfaReset = app(TenantContext::class)->withinPrincipal(
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            fn () => DB::selectOne(
                'SELECT security.save_client_mfa(?, ?, ?::jsonb)::text AS saved',
                [
                    'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                    'attacker-controlled-ciphertext',
                    json_encode([password_hash('unused', PASSWORD_BCRYPT)], JSON_THROW_ON_ERROR),
                ],
            )->saved,
        );
        $this->assertSame('false', $mfaReset);

        $this->assertNotEmpty($enrollment['recovery_code']);
        $this->assertContextCleared();
    }

    public function test_client_admin_sign_in_requires_totp_and_recovery_codes_are_single_use(): void
    {
        $email = 'g2-mfa-admin@example.invalid';
        $enrollment = $this->completeClientAdminMfaEnrollment(
            $email,
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        );

        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', [
            'email' => $email,
            'password' => 'G2 CI fixture password',
        ])->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();

        $this->post(route('mfa.verify'), ['code' => ClientTotp::currentCode($enrollment['secret'])])
            ->assertRedirect(route('home'));
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', [
            'email' => $email,
            'password' => 'G2 CI fixture password',
        ])->assertRedirect(route('mfa.challenge'));
        $this->post(route('mfa.verify'), ['code' => $enrollment['recovery_code']])
            ->assertRedirect(route('home'));
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', [
            'email' => $email,
            'password' => 'G2 CI fixture password',
        ])->assertRedirect(route('mfa.challenge'));
        $this->post(route('mfa.verify'), ['code' => $enrollment['recovery_code']])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_client_totp_matches_the_rfc_6238_sha1_test_vector(): void
    {
        $this->assertTrue(ClientTotp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59));
        $this->assertFalse(ClientTotp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '000000', 59));
    }

    public function test_password_reset_token_is_single_use_and_revokes_old_sessions(): void
    {
        Mail::fake();

        $email = 'g2-reset@example.invalid';
        $this->post('/login', [
            'email' => $email,
            'password' => 'G2 CI fixture password',
        ])->assertRedirect(route('home'));
        $oldUser = Auth::user();
        $this->assertInstanceOf(User::class, $oldUser);
        $this->assertSame(1, $oldUser->authVersion);
        $this->assertSame(1, session('auth.auth_version'));

        $this->post(route('password.email'), ['email' => $email])
            ->assertSessionHas('status', 'If an active account matches that email, a password reset link will be sent.');
        $cooldownReset = DB::selectOne('SELECT * FROM security.issue_client_password_reset(?)', [$email]);
        $this->assertNull($cooldownReset);
        $sentMail = Mail::sent(ClientPasswordResetMail::class)->first();
        $this->assertInstanceOf(ClientPasswordResetMail::class, $sentMail);

        $urlParts = parse_url($sentMail->resetUrl);
        parse_str($urlParts['query'] ?? '', $query);
        $token = basename($urlParts['path'] ?? '');
        $this->assertSame($email, $query['email'] ?? null);
        $this->get(route('password.reset.form', ['token' => $token, 'email' => $email]))->assertOk();

        $newPassword = 'New secure G2 password 2026';
        $this->post(route('password.reset'), [
            'email' => $email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'));
        $this->assertGuest();

        Auth::login($oldUser);
        session()->regenerate();
        session()->put('auth.auth_version', $oldUser->authVersion);
        Auth::forgetGuards();
        $this->get('/workspace/select')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', [
            'email' => $email,
            'password' => 'G2 CI fixture password',
        ])->assertSessionHasErrors('email');
        $this->post('/login', [
            'email' => $email,
            'password' => $newPassword,
        ])->assertRedirect(route('home'));
        $this->assertAuthenticated();

        $reused = DB::selectOne(
            'SELECT security.complete_client_password_reset(?, ?, ?)::text AS completed',
            [$email, $token, Hash::make('Another secure password 2026')],
        )->completed;
        $this->assertSame('false', $reused);
    }

    public function test_password_reset_is_suppressed_when_production_mail_or_url_is_insecure(): void
    {
        Mail::fake();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'production');
        config([
            'app.url' => 'http://localhost',
            'mail.default' => 'log',
        ]);

        $this->post(route('password.email'), ['email' => 'g2-reset@example.invalid'])
            ->assertRedirect(route('password.forgot'))
            ->assertSessionHas('status', 'If an active account matches that email, a password reset link will be sent.');

        Mail::assertNothingSent();
    }

    public function test_principal_cannot_select_another_principals_membership(): void
    {
        $request = Request::create('/', 'GET', [
            'account_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'property_id' => '22222222-2222-4222-8222-222222222222',
        ]);
        $request->setUserResolver(fn (): User => $this->user('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));

        try {
            app(ApplyTenantContext::class)->handle($request, fn (): Response => new Response);
            $this->fail('A principal must not select another principal\'s membership.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertContextCleared();
    }

    public function test_signed_principal_context_resolves_only_its_active_membership(): void
    {
        $principalId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $membership = app(TenantContext::class)->withinPrincipal($principalId, function () use ($principalId): array {
            $context = DB::selectOne('SELECT g2_proof.valid_principal_context() AS is_valid,
                                             g2_proof.current_principal_id()::text AS principal_id');

            return [
                'context' => $context,
                'own_count' => DB::table('g2_proof.account_memberships')->where('user_id', $principalId)->count(),
                'other_count' => DB::table('g2_proof.account_memberships')
                    ->where('user_id', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
                    ->count(),
            ];
        });

        $this->assertTrue($membership['context']->is_valid);
        $this->assertSame($principalId, $membership['context']->principal_id);
        $this->assertSame(1, $membership['own_count']);
        $this->assertSame(0, $membership['other_count']);
        $this->assertContextCleared();
    }

    public function test_database_policy_rejects_replayed_context_without_principal_or_active_membership(): void
    {
        $accountId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $propertyId = '22222222-2222-4222-8222-222222222222';
        $principalId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $key = hex2bin((string) config('tenancy.context_key_hex'));

        if ($key === false) {
            $this->fail('The G2 signing key must be valid for this test.');
        }

        try {
            DB::transaction(function () use ($accountId, $propertyId, $principalId, $key): void {
                $transactionId = (string) DB::selectOne('SELECT txid_current() AS transaction_id')->transaction_id;
                DB::select("SELECT set_config('app.account_id', ?, true)", [$accountId]);
                DB::select("SELECT set_config('app.property_id', ?, true)", [$propertyId]);
                DB::select("SELECT set_config('app.context_signature', ?, true)", [
                    hash_hmac('sha256', $principalId.':'.$accountId.':'.$propertyId.':'.$transactionId, $key),
                ]);

                DB::table('g2_proof.tenant_records')->insert([
                    'record_id' => Str::uuid()->toString(),
                    'account_id' => $accountId,
                    'property_id' => $propertyId,
                    'payload' => 'replayed tenant context without a principal',
                ]);
            });
            $this->fail('A tenant signature without its principal must not authorize a write.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }

        try {
            app(TenantContext::class)->withinPrincipal('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', function () use ($key): void {
                $transactionId = (string) DB::selectOne('SELECT txid_current() AS transaction_id')->transaction_id;
                $accountId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
                $propertyId = '22222222-2222-4222-8222-222222222222';
                DB::select("SELECT set_config('app.account_id', ?, true)", [$accountId]);
                DB::select("SELECT set_config('app.property_id', ?, true)", [$propertyId]);
                DB::select("SELECT set_config('app.context_signature', ?, true)", [
                    hash_hmac('sha256', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:'.$accountId.':'.$propertyId.':'.$transactionId, $key),
                ]);
                DB::table('g2_proof.tenant_records')->insert([
                    'record_id' => Str::uuid()->toString(),
                    'account_id' => $accountId,
                    'property_id' => $propertyId,
                    'payload' => 'account A paired with property B without an active membership',
                ]);
            });
            $this->fail('A valid principal cannot pair an account with an unassigned property.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }

        try {
            app(TenantContext::class)->withinPrincipal($principalId, function () use ($accountId, $propertyId, $principalId, $key): void {
                $transactionId = (string) DB::selectOne('SELECT txid_current() AS transaction_id')->transaction_id;
                DB::select("SELECT set_config('app.account_id', ?, true)", [$accountId]);
                DB::select("SELECT set_config('app.property_id', ?, true)", [$propertyId]);
                DB::select("SELECT set_config('app.context_signature', ?, true)", [
                    hash_hmac('sha256', $principalId.':'.$accountId.':'.$propertyId.':'.$transactionId, $key),
                ]);

                DB::table('g2_proof.tenant_records')->insert([
                    'record_id' => Str::uuid()->toString(),
                    'account_id' => $accountId,
                    'property_id' => $propertyId,
                    'payload' => 'suspended principal tenant context',
                ]);
            });
            $this->fail('A suspended principal membership must not authorize a tenant write.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
        $this->assertContextCleared();
    }

    public function test_membership_resolver_accepts_the_seeded_active_membership(): void
    {
        $result = app(TenantMembershipResolver::class)->withinMembership(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            '11111111-1111-4111-8111-111111111111',
            fn (): string => 'authorized',
        );

        $this->assertSame('authorized', $result);
        $this->assertContextCleared();
    }

    public function test_membership_resolver_rejects_a_suspended_identity_membership(): void
    {
        $result = app(TenantMembershipResolver::class)->withinMembership(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            '22222222-2222-4222-8222-222222222222',
            fn (): string => 'must not authorize',
        );

        $this->assertNull($result);
        $this->assertContextCleared();
    }

    public function test_identity_memberships_use_forced_rls_and_owner_separation(): void
    {
        $table = DB::selectOne("SELECT c.relrowsecurity::text AS row_security,
                                       c.relforcerowsecurity::text AS forced_row_security,
                                       pg_get_userbyid(c.relowner) AS owner
                                FROM pg_class c
                                JOIN pg_namespace n ON n.oid = c.relnamespace
                                WHERE n.nspname = 'identity'
                                  AND c.relname = 'account_memberships'");

        $this->assertNotNull($table);
        $this->assertSame('true', $table->row_security);
        $this->assertSame('true', $table->forced_row_security);
        $this->assertSame('dawineks_owner', $table->owner);

        $visibleMemberships = app(TenantContext::class)->withinPrincipal(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            fn (): int => DB::table('identity.account_memberships')->count(),
        );

        $this->assertSame(1, $visibleMemberships);
        $this->assertSame(0, DB::table('information_schema.role_table_grants')
            ->where('table_schema', 'identity')
            ->where('table_name', 'users')
            ->where('grantee', 'dawineks_runtime')
            ->count());
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

    public function test_user_principals_use_string_uuidv7_identifiers(): void
    {
        $user = new User;

        $this->assertSame('string', $user->getKeyType());
        $this->assertFalse($user->getIncrementing());
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',
            $user->newUniqueId(),
        );
    }

    private function assertContextCleared(): void
    {
        $context = DB::selectOne("SELECT current_setting('app.account_id', true) AS account_id,
                                         current_setting('app.property_id', true) AS property_id,
                                         current_setting('app.context_signature', true) AS context_signature,
                                         current_setting('app.principal_id', true) AS principal_id,
                                         current_setting('app.principal_signature', true) AS principal_signature");

        $this->assertTrue($context->account_id === null || $context->account_id === '');
        $this->assertTrue($context->property_id === null || $context->property_id === '');
        $this->assertTrue($context->context_signature === null || $context->context_signature === '');
        $this->assertTrue($context->principal_id === null || $context->principal_id === '');
        $this->assertTrue($context->principal_signature === null || $context->principal_signature === '');
    }

    /** @return array{secret: string, recovery_code: string} */
    private function completeClientAdminMfaEnrollment(string $email, string $userId): array
    {
        $this->post('/login', [
            'email' => $email,
            'password' => 'G2 CI fixture password',
        ])->assertRedirect(route('mfa.setup'));

        $this->assertGuest();
        $setupResponse = $this->get(route('mfa.setup'))->assertOk();
        preg_match('/data-secret="([A-Z2-7]+)"/', $setupResponse->getContent(), $secretMatch);
        $this->assertNotEmpty($secretMatch[1] ?? null);
        $secret = $secretMatch[1];

        $recoveryResponse = $this->post(route('mfa.setup.confirm'), [
            'code' => ClientTotp::currentCode($secret),
        ])->assertOk()->assertSee('Save your recovery codes');
        $this->assertSame($userId, (string) Auth::id());
        preg_match('/<code>([A-F0-9]{6}-[A-F0-9]{6})<\/code>/', $recoveryResponse->getContent(), $recoveryMatch);
        $this->assertNotEmpty($recoveryMatch[1] ?? null);

        return [
            'secret' => $secret,
            'recovery_code' => $recoveryMatch[1],
        ];
    }

    private function user(string $id): User
    {
        $user = new User;
        $user->setAttribute('id', $id);

        return $user;
    }

    private function withinAuthorizedMembership(string $principalId, string $accountId, string $propertyId, Closure $callback): mixed
    {
        return app(TenantMembershipResolver::class)->withinMembership(
            $principalId,
            $accountId,
            $propertyId,
            fn (): mixed => $callback(),
        );
    }
}
