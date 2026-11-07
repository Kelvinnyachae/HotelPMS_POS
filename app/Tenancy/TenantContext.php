<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class TenantContext
{
    /**
     * Run tenant-scoped work inside a transaction so PostgreSQL's SET LOCAL
     * context is cleared automatically on both commit and rollback.
     */
    public function within(string $accountId, string $propertyId, Closure $callback): mixed
    {
        if (! self::isValidUuid($accountId) || ! self::isValidUuid($propertyId)) {
            throw new InvalidArgumentException('Tenant context requires valid account and property UUIDs.');
        }

        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Tenant context must own the outermost database transaction.');
        }

        $contextKeyHex = config('tenancy.context_key_hex');

        if (! is_string($contextKeyHex) || strlen($contextKeyHex) !== 64 || ! ctype_xdigit($contextKeyHex)) {
            throw new RuntimeException('A 256-bit tenant context signing key is required.');
        }

        $contextKey = hex2bin($contextKeyHex);

        if ($contextKey === false) {
            throw new RuntimeException('The tenant context signing key is invalid.');
        }

        $accountId = strtolower($accountId);
        $propertyId = strtolower($propertyId);
        $signature = hash_hmac('sha256', $accountId.':'.$propertyId, $contextKey);

        try {
            return $connection->transaction(function () use ($connection, $accountId, $propertyId, $signature, $callback): mixed {
                $connection->select("select set_config('app.account_id', ?, true)", [$accountId]);
                $connection->select("select set_config('app.property_id', ?, true)", [$propertyId]);
                $connection->select("select set_config('app.context_signature', ?, true)", [$signature]);

                return $callback();
            });
        } catch (Throwable $exception) {
            if ($connection->transactionLevel() > 0) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    DB::purge($connection->getName());
                }
            }

            throw $exception;
        }
    }

    public static function isValidUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $value) === 1;
    }
}
