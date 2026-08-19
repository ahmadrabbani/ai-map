<?php

namespace App\Http\Controllers;

use App\Models\CadApprovalPlan;
use App\Models\CadEvaluationRun;
use App\Models\CadPrediction;
use App\Models\CadTag;
use Illuminate\Http\Request;

class CadTaggingDashboardController extends Controller
{
    public function buildingPlans(Request $request)
    {
        $query = CadApprovalPlan::with(['application', 'submission.predictions', 'submission.tags'])
            ->whereNotNull('cad_submission_id');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($nested) use ($search) {
                $nested->where('label', 'like', "%{$search}%")
                    ->orWhereHas('application', fn ($application) => $application
                        ->where('applicant_name', 'like', "%{$search}%")
                        ->orWhere('plot_number', 'like', "%{$search}%")
                        ->orWhere('scheme', 'like', "%{$search}%"));
            });
        }
        if ($floor = $request->query('floor')) $query->where('floor_type', $floor);
        if ($status = $request->query('status')) $query->where('status', $status);

        $plans = $query->latest('id')->paginate(20)->withQueryString();
        return view('admin.cad-tagging.building-plans', compact('plans'));
    }

    public function accuracy(Request $request)
    {
        $runs = CadEvaluationRun::with('metrics')->latest('id')->limit(30)->get();
        $latest = $runs->first();
        $entityMetrics = $latest?->metrics->where('metric_scope', 'entity') ?? collect();
        $summary = $latest?->summary ?? [];
        $counts = [
            'drawings' => CadTag::distinct('cad_submission_id')->count('cad_submission_id'),
            'verified_entities' => CadTag::whereIn('verification_level', ['expert_verified', 'gold_standard'])->count(),
            'predictions' => CadPrediction::count(),
            'unreviewed' => CadPrediction::whereIn('status', ['unreviewed', 'ai_suggested'])->count(),
            'accepted' => CadPrediction::where('status', 'confirmed')->count(),
            'corrected' => CadPrediction::where('status', 'corrected')->count(),
            'rejected' => CadPrediction::where('status', 'rejected')->count(),
        ];

        return view('admin.cad-tagging.accuracy', compact('runs', 'latest', 'entityMetrics', 'summary', 'counts'));
    }
}
