<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdEpermitAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('admin.plan.bp.ad.login');
        }

        $allowedEmails = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            explode(',', (string) env('AD_EPERMIT_ALLOWED_EMAILS', ''))
        )));

        $role = strtolower((string) data_get($user, 'role', ''));
        $isAdByRole = in_array($role, ['ad_epermit', 'admin'], true);
        $isAdByFlag = (bool) data_get($user, 'is_ad_epermit', false);
        $isAdByEmail = in_array(strtolower((string) $user->email), $allowedEmails, true);

        if (! $isAdByRole && ! $isAdByFlag && ! $isAdByEmail) {
            abort(403, 'You do not have AD ePermit access.');
        }

        return $next($request);
    }
}
