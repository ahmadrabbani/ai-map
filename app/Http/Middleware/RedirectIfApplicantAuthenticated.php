<?php

namespace App\Http\Middleware;

use App\Models\Applicant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfApplicantAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (int) $request->session()->get('bp_applicant_id', 0);
        if ($id > 0) {
            $applicant = Applicant::find($id);
            if ($applicant && strtolower((string) $applicant->status) === 'active') {
                return redirect()->route('public.bp.dashboard');
            }
            $request->session()->forget('bp_applicant_id');
        }

        return $next($request);
    }
}
