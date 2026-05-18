<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdEpermitAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check() && $this->isAdEpermitUser(Auth::user())) {
            return redirect()->route('admin.plan.bp.ad.index');
        }

        return view('admin.building-plan.ad-login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, (bool) $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Invalid AD ePermit credentials.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        if (! $this->isAdEpermitUser($request->user())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'This account does not have AD ePermit access.'])->onlyInput('email');
        }

        return redirect()->intended(route('admin.plan.bp.ad.index'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.plan.bp.ad.login');
    }

    private function isAdEpermitUser($user): bool
    {
        $role = strtolower((string) data_get($user, 'role', ''));
        $allowedEmails = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)),
            explode(',', (string) env('AD_EPERMIT_ALLOWED_EMAILS', ''))
        )));
        $isAdByEmail = in_array(strtolower((string) $user->email), $allowedEmails, true);

        return (bool) data_get($user, 'is_ad_epermit', false)
            || in_array($role, ['ad_epermit', 'admin'], true)
            || $isAdByEmail;
    }
}
