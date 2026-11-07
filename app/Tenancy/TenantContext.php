<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Database\LostConnectionDetector;
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

        $accountId = strtolower($accountId);
        $propertyId = strtolower($propertyId);

        return $this->withinTransaction(function ($connection) use ($accountId, $propertyId, $callback): mixed {
            $connection->select("select set_config('app.account_id', ?, true)", [$accountId]);
            $connection->select("select set_config('app.property_id', ?, true)", [$propertyId]);
            $connection->select("select set_config('app.context_signature', ?, true)", [
                hash_hmac('sha256', $accountId.':'.$propertyId, $this->signingKey()),
            ]);

            return $callback();
        });
    }

    /**
     * Resolve identity memberships inside a signed, transaction-local principal context.
     * The database RLS policy exposes only this principal's membership rows.
     */
    public function withinPrincipal(string $principalId, Closure $callback): mixed
    {
        if (! self::isValidUuid($principalId)) {
            throw new InvalidArgumentException('Principal context requires a valid user UUID.');
        }

        $principalId = strtolower($principalId);

        return $this->withinTransaction(function ($connection) use ($principalId, $callback): mixed {
            $connection->select("select set_config('app.principal_id', ?, true)", [$principalId]);
            $connection->select("select set_config('app.principal_signature', ?, true)", [
                hash_hmac('sha256', 'principal:'.$principalId, $this->signingKey()),
            ]);

            return $callback();
        });
    }

    /**
     * Authorize a requested membership and run tenant work in the same transaction.
     * This prevents membership revocation racing between lookup and tenant access.
     */
    public function withinMembership(
        string $principalId,
        string $accountId,
        string $propertyId,
        Closure $authorize,
        Closure $callback,
    ): mixed {
        if (! self::isValidUuid($principalId) || ! self::isValidUuid($accountId) || ! self::isValidUuid($propertyId)) {
            throw new InvalidArgumentException('Membership context requires valid user, account, and property UUIDs.');
        }

        $principalId = strtolower($principalId);
        $accountId = strtolower($accountId);
        $propertyId = strtolower($propertyId);

        return $this->withinTransaction(function ($connection) use ($principalId, $accountId, $propertyId, $authorize, $callback): mixed {
            $connection->select("select set_config('app.principal_id', ?, true)", [$principalId]);
            $connection->select("select set_config('app.principal_signature', ?, true)", [
                hash_hmac('sha256', 'principal:'.$principalId, $this->signingKey()),
            ]);

            if (! $authorize($connection)) {
                return null;
            }

            $connection->select("select set_config('app.account_id', ?, true)", [$accountId]);
            $connection->select("select set_config('app.property_id', ?, true)", [$propertyId]);
            $connection->select("select set_config('app.context_signature', ?, true)", [
                hash_hmac('sha256', $accountId.':'.$propertyId, $this->signingKey()),
            ]);

            return $callback();
        });
    }

    private function withinTransaction(Closure $callback): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            throw new LogicException('Tenant and principal contexts must own the outermost database transaction.');
        }

        // Validate configuration before opening a transaction.
        $this->signingKey();

        try {
            return $connection->transaction(fn () => $callback($connection));
        } catch (Throwable $exception) {
            $purgeConnection = (new LostConnectionDetector)->causedByLostConnection($exception);

            if ($connection->transactionLevel() > 0) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    $purgeConnection = true;
                }
            }

            if ($purgeConnection) {
                DB::purge($connection->getName());
            }

            throw $exception;
        }
    }

    private function signingKey(): string
    {
        $contextKeyHex = config('tenancy.context_key_hex');

        if (! is_string($contextKeyHex) || strlen($contextKeyHex) !== 64 || ! ctype_xdigit($contextKeyHex)) {
            throw new RuntimeException('A 256-bit tenant context signing key is required.');
        }

        $contextKey = hex2bin($contextKeyHex);

        if ($contextKey === false) {
            throw new RuntimeException('The tenant context signing key is invalid.');
        }

        return $contextKey;
    }

    public static function isValidUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $value) === 1;
    }
}
