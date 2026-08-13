<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PublicApplicantAuthController extends Controller
{
    public function showLogin(): View
    {
        return view('public.building-plan.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'password' => ['required', 'string'],
        ]);

        $applicant = Applicant::where('cnic', $data['cnic'])->first();
        if (! $applicant || ! Hash::check($data['password'], $applicant->password)) {
            return back()->withErrors(['cnic' => 'Invalid CNIC or password.'])->withInput();
        }

        if (strtolower((string) $applicant->status) !== 'active') {
            return back()->withErrors(['cnic' => 'Account is not active.']);
        }

        $request->session()->put('bp_applicant_id', $applicant->id);
        $request->session()->regenerate();

        return redirect()->route('public.bp.dashboard');
    }

    public function showRegister(): View
    {
        return view('public.building-plan.auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/', 'unique:applicants,cnic'],
            'mobile' => ['required', 'regex:/^(?:\+92|0)3\d{2}-?\d{7}$/'],
            'email' => ['required', 'email', 'max:255', 'unique:applicants,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $applicant = Applicant::create([
            'name' => $data['name'],
            'cnic' => $data['cnic'],
            'mobile' => $data['mobile'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        $request->session()->put('bp_applicant_id', $applicant->id);
        $request->session()->regenerate();

        return redirect()->route('public.bp.dashboard')->with('status', 'Registration completed successfully.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('bp_applicant_id');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('public.bp.login');
    }
}
