<?php

namespace App\Tenancy;

use Closure;

final class TenantMembershipResolver
{
    public function __construct(private TenantContext $tenantContext) {}

    public function withinMembership(string $principalId, mixed $accountId, mixed $propertyId, Closure $callback): mixed
    {
        if (! TenantContext::isValidUuid($principalId)
            || ! is_string($accountId)
            || ! is_string($propertyId)
            || ! TenantContext::isValidUuid($accountId)
            || ! TenantContext::isValidUuid($propertyId)) {
            return null;
        }

        $accountId = strtolower($accountId);
        $propertyId = strtolower($propertyId);
        $table = config('tenancy.membership_table', 'identity.account_memberships');

        return $this->tenantContext->withinMembership(
            strtolower($principalId),
            $accountId,
            $propertyId,
            fn ($connection): bool => $connection->table($table)
                ->where('user_id', strtolower($principalId))
                ->where('account_id', $accountId)
                ->where('property_id', $propertyId)
                ->where('status', 'active')
                ->exists(),
            fn (): mixed => $callback(new TenantMembership($accountId, $propertyId)),
        );
    }
}
