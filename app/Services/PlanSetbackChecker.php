<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class PlanSetbackChecker
{
    /**
     * @param string $pdfPath absolute path to PDF
     * @param float $requiredSetbackFt e.g. 5.0
     * @return array
     */
    public function run(string $pdfPath, float $requiredSetbackFt = 5.0): array
    {
        // path to your python script
        $script = base_path('scripts/check_setback.py');

        if (!file_exists($script)) {
            return [
                'status' => 'error',
                'message' => 'Python script not found at ' . $script,
            ];
        }

        // run: python check_setback.py plan.pdf --required=5
        $process = new Process([
            $this->pythonBin(),
            $script,
            $pdfPath,
            '--required=' . $requiredSetbackFt,
            '--storage-root=' . storage_path('app'),
            '--json'
        ]);
        $process->setTimeout(120); // seconds

        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $data = json_decode($stdout, true);

        if (!$process->isSuccessful()) {
            $message = $data['message'] ?? null;
            if (!$message) {
                $message = 'Python failed' . ($stderr ? ': ' . $stderr : '');
            }

            return [
                'status' => 'error',
                'message' => $message,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'python_payload' => $data,
            ];
        }

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return [
                'status' => 'error',
                'message' => 'Could not decode Python JSON: ' . json_last_error_msg(),
                'raw' => $stdout,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        return $data;
    }

    private function pythonBin(): string
    {
        $configured = env('PYTHON_BIN');
        if (is_string($configured) && $configured !== '' && is_file($configured) && is_executable($configured)) {
            return $configured;
        }

        return 'python3';
    }
}
