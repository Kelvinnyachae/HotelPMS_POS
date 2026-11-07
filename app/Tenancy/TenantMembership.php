<?php

namespace App\Tenancy;

final readonly class TenantMembership
{
    public function __construct(
        public string $accountId,
        public string $propertyId,
    ) {}
}
