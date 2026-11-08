<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ClientWorkspaceController extends Controller
{
    public function select(Request $request): View
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $memberships = app(TenantContext::class)->withinPrincipal(
            $user->getAuthIdentifier(),
            fn () => DB::table('identity.account_memberships')
                ->select(['account_id', 'property_id'])
                ->where('user_id', $user->getAuthIdentifier())
                ->where('status', 'active')
                ->orderBy('account_id')
                ->orderBy('property_id')
                ->get(),
        );

        return view('workspace-select', ['memberships' => $memberships]);
    }
}
