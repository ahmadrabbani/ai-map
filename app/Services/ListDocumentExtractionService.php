<?php

namespace App\Services;

use ZipArchive;

class ListDocumentExtractionService
{
    public function extractFromDocx(string $absolutePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($absolutePath) !== true) {
            return $this->emptyResult('Unable to open DOCX file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || trim($xml) === '') {
            return $this->emptyResult('word/document.xml not found.');
        }

        $doc = new \DOMDocument();
        @$doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = [];
        foreach ($xpath->query('//w:tbl') as $tbl) {
            $rows = [];
            foreach ($xpath->query('.//w:tr', $tbl) as $tr) {
                $cells = [];
                foreach ($xpath->query('.//w:tc', $tr) as $tc) {
                    $textNodes = $xpath->query('.//w:t', $tc);
                    $parts = [];
                    foreach ($textNodes as $tn) {
                        $parts[] = trim((string) $tn->textContent);
                    }
                    $cell = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts))));
                    $cells[] = $cell;
                }
                if (! empty(array_filter($cells, fn ($v) => $v !== ''))) {
                    $rows[] = $cells;
                }
            }
            if (! empty($rows)) {
                $tables[] = $rows;
            }
        }

        $allRows = [];
        foreach ($tables as $table) {
            foreach ($table as $row) {
                $allRows[] = $row;
            }
        }

        $flatText = strtolower(implode("\n", array_map(fn ($r) => implode(' | ', $r), $allRows)));
        $applicant = $this->extractApplicantData($allRows, $flatText);
        $plot = $this->extractPlotData($allRows, $flatText);
        $layerRows = $this->extractLayerRows($allRows);
        $sections = $this->extractSectionCards($allRows);
        $measurementMetrics = $this->extractMeasurementMetrics($allRows, $flatText);

        return [
            'ok' => true,
            'applicant' => $applicant,
            'plot' => $plot,
            'layers' => $layerRows,
            'sections' => $sections,
            'measurement_metrics' => $measurementMetrics,
            'rows_count' => count($allRows),
        ];
    }

    private function extractApplicantData(array $rows, string $flatText): array
    {
        $out = [
            'name' => null,
            'email' => null,
            'phone' => null,
            'raw' => [],
        ];

        foreach ($rows as $row) {
            $line = strtolower(implode(' ', $row));
            if (str_contains($line, 'applicant') || str_contains($line, 'owner') || str_contains($line, 'name')) {
                $out['raw'][] = $row;
                $value = $this->pickBestValueFromRow($row);
                if (! $out['name'] && $value) {
                    $out['name'] = $value;
                }
            }
            if (! $out['email']) {
                foreach ($row as $cell) {
                    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $cell, $m)) {
                        $out['email'] = $m[0];
                        break;
                    }
                }
            }
            if (! $out['phone']) {
                foreach ($row as $cell) {
                    if (preg_match('/(?:\+?\d[\d\s\-]{8,}\d)/', $cell, $m)) {
                        $out['phone'] = trim($m[0]);
                        break;
                    }
                }
            }
        }

        if (! $out['email'] && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $flatText, $m)) {
            $out['email'] = $m[0];
        }

        return $out;
    }

    private function extractPlotData(array $rows, string $flatText): array
    {
        $out = [
            'plot_no' => null,
            'plot_size' => null,
            'street' => null,
            'raw' => [],
        ];

        foreach ($rows as $row) {
            $line = strtolower(implode(' ', $row));
            if (str_contains($line, 'plot') || str_contains($line, 'site') || str_contains($line, 'khasra')) {
                $out['raw'][] = $row;
            }
            if (! $out['plot_no'] && preg_match('/\bplot\s*(?:no\.?|number)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/i', implode(' ', $row), $m)) {
                $out['plot_no'] = trim($m[1]);
            }
            if (! $out['plot_size'] && preg_match('/\b(\d+(?:\.\d+)?)\s*(marla|kanal|sq\s*ft|sqft|square\s*feet)\b/i', implode(' ', $row), $m)) {
                $out['plot_size'] = trim($m[0]);
            }
            if (! $out['street'] && preg_match('/\b(street|road)\b\s*[:\-]?\s*([A-Z0-9\-\s]+)/i', implode(' ', $row), $m)) {
                $out['street'] = trim($m[2]);
            }
        }

        if (! $out['plot_no'] && preg_match('/\bplot\s*(?:no\.?|number)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/i', $flatText, $m)) {
            $out['plot_no'] = trim($m[1]);
        }

        return $out;
    }

    private function extractLayerRows(array $rows): array
    {
        $layerRows = [];
        foreach ($rows as $row) {
            $line = strtolower(implode(' ', $row));
            $looksLikeLayer = str_contains($line, 'layer') || preg_match('/\b(plot boundary|front building line|rear building line|side building line|external walls|dimension|text)\b/i', $line);
            if ($looksLikeLayer) {
                $layerRows[] = $row;
            }
        }

        return array_slice($layerRows, 0, 300);
    }

    private function pickBestValueFromRow(array $row): ?string
    {
        $cells = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $row), fn ($v) => $v !== ''));
        if (count($cells) < 2) {
            return $cells[0] ?? null;
        }

        return $cells[count($cells) - 1] ?: null;
    }

    private function emptyResult(string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'applicant' => ['name' => null, 'email' => null, 'phone' => null, 'raw' => []],
            'plot' => ['plot_no' => null, 'plot_size' => null, 'street' => null, 'raw' => []],
            'layers' => [],
            'sections' => [],
            'measurement_metrics' => [],
            'rows_count' => 0,
        ];
    }

    private function extractSectionCards(array $rows): array
    {
        $cards = [];
        foreach ($rows as $row) {
            $cells = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $row), fn ($v) => $v !== ''));
            if (count($cells) < 2) {
                continue;
            }

            $first = $cells[0];
            $second = $cells[1];
            if (! preg_match('/^\d{1,3}$/', $first)) {
                continue;
            }

            $title = $second;
            $desc = $cells[2] ?? null;
            $titleLow = strtolower($title);
            $isKnown = str_contains($titleLow, 'applicant')
                || str_contains($titleLow, 'plot')
                || str_contains($titleLow, 'measurement')
                || str_contains($titleLow, 'submission')
                || str_contains($titleLow, 'owner');
            if (! $isKnown) {
                continue;
            }

            $cards[] = [
                'key' => $first,
                'title' => $title,
                'description' => $desc,
                'raw' => $cells,
            ];
        }

        return $cards;
    }

    private function extractMeasurementMetrics(array $rows, string $flatText): array
    {
        $metrics = [
            'plot_area' => null,
            'ground_floor_covered' => null,
            'total_floor_covered' => null,
            'coverage_percent' => null,
            'far' => null,
        ];

        $allLines = array_map(fn ($r) => strtolower(implode(' ', (array) $r)), $rows);
        $allLines[] = strtolower($flatText);

        foreach ($allLines as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }
            if ($metrics['plot_area'] === null && str_contains($line, 'plot area')) {
                $metrics['plot_area'] = $this->firstNumber($line);
            }
            if ($metrics['ground_floor_covered'] === null && (str_contains($line, 'ground floor') && (str_contains($line, 'covered') || str_contains($line, 'coverage')))) {
                $metrics['ground_floor_covered'] = $this->firstNumber($line);
            }
            if ($metrics['total_floor_covered'] === null && (str_contains($line, 'total covered') || str_contains($line, 'all floor covered'))) {
                $metrics['total_floor_covered'] = $this->firstNumber($line);
            }
            if ($metrics['coverage_percent'] === null && (str_contains($line, 'coverage') || str_contains($line, 'covered area'))) {
                $metrics['coverage_percent'] = $this->firstPercent($line);
            }
            if ($metrics['far'] === null && str_contains($line, 'far')) {
                $metrics['far'] = $this->firstNumber($line);
            }
        }

        return $metrics;
    }

    private function firstNumber(string $line): ?float
    {
        if (! preg_match('/\b(\d+(?:\.\d+)?)\b/', $line, $m)) {
            return null;
        }
        return round((float) $m[1], 4);
    }

    private function firstPercent(string $line): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $line, $m)) {
            return round((float) $m[1], 4);
        }
        return null;
    }
}
