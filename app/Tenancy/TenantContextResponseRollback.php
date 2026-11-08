<?php

namespace App\Tenancy;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TenantContextResponseRollback extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Tenant request returned an error response; its transaction was rolled back.');
    }
}
