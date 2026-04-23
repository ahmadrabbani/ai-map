<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use App\Services\PlanSetbackChecker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\PlanCheck;

class PlanCheckController extends Controller
{
    protected $checker;

    public function __construct(PlanSetbackChecker $checker)
    {
        $this->checker = $checker;
    }

    /**
     * Simple web interface to upload PDF and see results.
     */
    public function showForm()
    {
        return view('admin.plans.check_setback', [
            'result'     => null,
            'attributes' => null,
        ]);
    }

    /**
     * Handle form POST (web UI).
     */
    public function checkSetbackWeb(Request $request)
    {
        $request->validate([
            'plan_pdf'            => 'required|file|mimes:pdf,dwg|max:51200',
            'required_setback_ft' => 'nullable|numeric',
        ]);

        $file = $request->file('plan_pdf');
        $requiredSetback = (float) $request->input('required_setback_ft', 5);

        $run = $this->storeAndRunCheck($file, $requiredSetback);
        $result = $run['result'];

        // Optionally delete temp file
        // Storage::delete($path);

        // If the service says "error", handle it for web
        if (($result['status'] ?? 'error') === 'error') {
            Log::error('Python setback check failed', [
                'message' => $result['message'] ?? null,
                'stderr'  => $result['stderr'] ?? null,
                'stdout'  => $result['stdout'] ?? null,
            ]);

            return back()
                ->withErrors([
                    'python' => $result['message'] ?? 'Python setback check failed.',
                ])
                ->withInput();
        }

        $attributes = $run['attributes'];

        return view('admin.plans.check_setback', [
            'result'     => $result,
            'attributes' => $attributes,
        ]);
    }

    private function storeAndRunCheck(UploadedFile $file, float $requiredSetback): array
    {
        $uploadDir = 'uploads/plans';
        $disk = Storage::disk('local');
        $disk->makeDirectory($uploadDir);
        $extension = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        $storedPath = $file->storeAs($uploadDir, Str::uuid() . '.' . $extension, 'local');
        $absolutePath = $disk->path($storedPath);

        if (!file_exists($absolutePath)) {
            Log::error('Stored PDF missing before Python run', [
                'stored_path' => $storedPath,
                'absolute_path' => $absolutePath,
            ]);

            return [
                'result' => [
                    'status' => 'error',
                    'message' => 'Upload could not be saved locally for processing.',
                ],
                'attributes' => null,
            ];
        }

        $result = $this->checker->run($absolutePath, $requiredSetback);
        $result = $this->appendVisualizationUrls($result);

        PlanCheck::create([
            'original_filename'     => $file->getClientOriginalName(),
            'stored_path'           => $storedPath,
            'required_setback_ft'   => $requiredSetback,
            'global_min_setback_ft' => $result['global_min_setback_ft'] ?? null,
            'left_setback_ft'       => $result['left_setback_ft'] ?? null,
            'right_setback_ft'      => $result['right_setback_ft'] ?? null,
            'meets_requirement'     => $result['meets_requirement'] ?? false,
            'raw_result'            => $result,
        ]);

        return [
            'result'     => $result,
            'attributes' => $result['attributes'] ?? null,
        ];
    }

    /**
     * JSON API endpoint.
     */
    public function checkSetback(Request $request)
    {
        $request->validate([
            'plan_pdf'            => 'required|file|mimes:pdf,dwg|max:51200',
            'required_setback_ft' => 'nullable|numeric',
        ]);

        $file = $request->file('plan_pdf');
        $requiredSetback = (float) $request->input('required_setback_ft', 5);
        $run = $this->storeAndRunCheck($file, $requiredSetback);
        $result = $run['result'];

        // Optionally delete temp file
        // Storage::delete($path);

        if (($result['status'] ?? 'error') === 'error') {
            Log::error('Python setback API check failed', [
                'message' => $result['message'] ?? null,
                'stderr'  => $result['stderr'] ?? null,
                'stdout'  => $result['stdout'] ?? null,
            ]);

            return response()->json($result, 500);
        }

        return response()->json($result);
    }

    private function appendVisualizationUrls(array $result): array
    {
        if (empty($result['visualizations']) || !is_array($result['visualizations'])) {
            return $result;
        }

        $result['visualizations'] = array_map(function ($viz) {
            if (!empty($viz['public_path'])) {
                $viz['public_url'] = asset('storage/' . ltrim($viz['public_path'], '/'));
            }
            return $viz;
        }, $result['visualizations']);

        return $result;
    }
}
