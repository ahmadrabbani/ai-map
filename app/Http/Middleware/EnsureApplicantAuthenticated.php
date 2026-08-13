<?php

namespace App\Http\Middleware;

use App\Models\Applicant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicantAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (int) $request->session()->get('bp_applicant_id', 0);
        if ($id <= 0) {
            return redirect()->route('public.bp.login')->with('status', 'Please login to continue.');
        }

        $applicant = Applicant::find($id);
        if (! $applicant || strtolower((string) $applicant->status) !== 'active') {
            $request->session()->forget('bp_applicant_id');
            return redirect()->route('public.bp.login')->with('status', 'Your account is not active.');
        }

        $request->attributes->set('bpApplicant', $applicant);

        return $next($request);
    }
}
