<?php

namespace App\Services;

class QrCodeService
{
    public function generateToken(): string
    {
        return (string) str()->uuid();
    }

    public function verificationUrl(string $token): string
    {
        return route('admin.plan.bp.verify', ['token' => $token]);
    }

    public function qrImageUrl(string $verificationUrl): string
    {
        // No QR package dependency required. This URL can be replaced by local QR rendering later.
        return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($verificationUrl);
    }
}
