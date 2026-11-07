<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplyTenantContext
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * Apply only server-resolved membership values. A trusted membership
     * resolver must populate these request attributes after authentication.
     * Client headers, query values, and route parameters are never consulted.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() !== null, 401);

        $accountId = $request->attributes->get('tenant.account_id');
        $propertyId = $request->attributes->get('tenant.property_id');

        abort_unless(
            is_string($accountId)
            && is_string($propertyId)
            && TenantContext::isValidUuid($accountId)
            && TenantContext::isValidUuid($propertyId),
            403,
        );

        return $this->tenantContext->within($accountId, $propertyId, fn (): Response => $next($request));
    }
}
