<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPlanAdEpermitSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->is('admin/plan/ad-epermit/login')
            || $request->is('admin/plan/ad-epermit/logout')
        ) {
            return $next($request);
        }

        if (! $request->is('admin/plan*')) {
            return $next($request);
        }

        if (
            $request->routeIs('admin.plan.bp.ad.login')
            || $request->routeIs('admin.plan.bp.ad.login.store')
            || $request->routeIs('admin.plan.bp.ad.logout')
        ) {
            return $next($request);
        }

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
            return redirect()->route('admin.plan.bp.ad.login');
        }

        return $next($request);
    }
}
