<?php

namespace App\Services;

class DocumentValidationService
{
    public function validate(string $absolutePath, ?string $mimeType, int $maxKb = 5120): array
    {
        if (! is_file($absolutePath)) {
            return [
                'validation_status' => 'invalid',
                'validation_message' => 'File not found for validation.',
            ];
        }

        $sizeKb = (int) ceil((filesize($absolutePath) ?: 0) / 1024);
        if ($sizeKb > $maxKb) {
            return [
                'validation_status' => 'invalid',
                'validation_message' => "File exceeds limit of {$maxKb} KB.",
            ];
        }

        $mime = strtolower((string) ($mimeType ?: mime_content_type($absolutePath)));
        $allowed = [
            'image/jpeg',
            'image/png',
            'application/pdf',
        ];

        if (! in_array($mime, $allowed, true)) {
            return [
                'validation_status' => 'invalid',
                'validation_message' => 'Unsupported file type. Allowed: JPG, PNG, PDF.',
            ];
        }

        if (str_starts_with($mime, 'image/')) {
            $img = @getimagesize($absolutePath);
            if (! is_array($img)) {
                return [
                    'validation_status' => 'needs_review',
                    'validation_message' => 'Image could not be fully read and needs manual review.',
                ];
            }
        }

        return [
            'validation_status' => 'valid',
            'validation_message' => 'Basic validation passed.',
        ];
    }
}
