<?php

namespace App\Services;

use App\Models\BpApplication;

class BuildingPlanNumberService
{
    public function generate(): string
    {
        $date = now()->format('Ymd');
        $prefix = "BP-{$date}-";

        $last = BpApplication::query()
            ->where('application_number', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('application_number');

        $lastSeq = 0;
        if ($last && preg_match('/^(?:BP-\d{8}-)(\d{6})$/', $last, $m)) {
            $lastSeq = (int) $m[1];
        }

        return $prefix . str_pad((string) ($lastSeq + 1), 6, '0', STR_PAD_LEFT);
    }
}
