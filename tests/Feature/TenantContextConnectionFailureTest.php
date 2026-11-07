<?php

namespace Tests\Feature;

use App\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class TenantContextConnectionFailureTest extends TestCase
{
    public function test_failed_context_rollback_purges_the_connection_from_the_manager(): void
    {
        config(['tenancy.context_key_hex' => str_repeat('a', 64)]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->twice()->andReturn(0, 1);
        $connection->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type(\Closure::class))
            ->andThrow(new RuntimeException('Simulated request failure.'));
        $connection->shouldReceive('rollBack')
            ->once()
            ->andThrow(new RuntimeException('Simulated rollback failure.'));
        $connection->shouldReceive('getName')->once()->andReturn('pgsql');

        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('purge')->once()->with('pgsql');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated request failure.');

        app(TenantContext::class)->within(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            '11111111-1111-4111-8111-111111111111',
            fn (): null => null,
        );
    }

    public function test_lost_connection_is_purged_when_transaction_level_has_already_reset(): void
    {
        config(['tenancy.context_key_hex' => str_repeat('a', 64)]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->twice()->andReturn(0, 0);
        $connection->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type(\Closure::class))
            ->andThrow(new RuntimeException('server closed the connection unexpectedly'));
        $connection->shouldReceive('getName')->once()->andReturn('pgsql');

        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('purge')->once()->with('pgsql');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('server closed the connection unexpectedly');

        app(TenantContext::class)->within(
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            '11111111-1111-4111-8111-111111111111',
            fn (): null => null,
        );
    }
}
