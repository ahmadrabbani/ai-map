<?php

namespace App\Services;

use App\Models\PublicBuildingPlanApplication;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class AttachmentZipService
{
    public function buildForApplication(PublicBuildingPlanApplication $application, array $extraFiles = []): ?string
    {
        $disk = Storage::disk('local');
        $zipDir = 'uploads/dfps-zips/' . $application->id;
        $disk->makeDirectory($zipDir);
        $zipPath = $disk->path($zipDir . '/' . ($application->application_no ?: 'application-' . $application->id) . '.zip');

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $this->addIfExists($zip, $disk, (string) $application->cad_file_path, 'cad/' . basename((string) $application->cad_file_path));
        $this->addIfExists($zip, $disk, (string) $application->plan_file_path, 'cad/' . basename((string) $application->plan_file_path));
        $this->addIfExists($zip, $disk, (string) $application->ai_report_path, 'ai-report/' . basename((string) $application->ai_report_path));

        foreach ($application->documents as $doc) {
            $path = (string) ($doc->file_path ?? '');
            $name = (string) ($doc->original_name ?: basename($path));
            $this->addIfExists($zip, $disk, $path, 'applicant-documents/' . $name);
        }

        foreach ($extraFiles as $localPath => $zipEntry) {
            if (is_string($localPath) && is_file($localPath) && is_string($zipEntry) && $zipEntry !== '') {
                $zip->addFile($localPath, $zipEntry);
            }
        }

        $zip->close();
        return $disk->exists($zipDir . '/' . basename($zipPath)) ? ($zipDir . '/' . basename($zipPath)) : null;
    }

    private function addIfExists(ZipArchive $zip, $disk, string $path, string $entry): void
    {
        if ($path === '' || ! $disk->exists($path)) {
            return;
        }

        $abs = $disk->path($path);
        if (is_file($abs)) {
            $zip->addFile($abs, $entry);
        }
    }
}
