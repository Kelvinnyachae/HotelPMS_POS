<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantMembershipResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplyTenantContext
{
    public function __construct(
        private TenantMembershipResolver $membershipResolver,
    ) {}

    /**
     * Request values select a desired account/property only. The authenticated
     * principal's signed database membership is the authority for the context.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() !== null, 401);

        $principalId = $request->user()->getAuthIdentifier();
        abort_unless(is_string($principalId) || is_int($principalId), 403);

        $response = $this->membershipResolver->withinMembership(
            (string) $principalId,
            $request->input('account_id'),
            $request->input('property_id'),
            fn (): Response => $next($request),
        );

        abort_unless($response instanceof Response, 403);

        return $response;
    }
}
