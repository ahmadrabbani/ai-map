<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$defaultSource = '/Users/ditit/Downloads/overview-of-lda-ai-verification-process-script (1).txt';
$source = $argv[1] ?? $defaultSource;
$outputDir = $root . '/demo-output/voiceover';

if (! is_file($source)) {
    fwrite(STDERR, "Source script not found: {$source}\n");
    exit(1);
}

@mkdir($outputDir, 0775, true);

$raw = file_get_contents($source);
if ($raw === false) {
    fwrite(STDERR, "Unable to read source script: {$source}\n");
    exit(1);
}

$part2Marker = 'Part 2: Administrative Review of the Application';
$part2Pos = strpos($raw, $part2Marker);
if ($part2Pos === false) {
    fwrite(STDERR, "Could not find Part 2 marker in source script.\n");
    exit(1);
}

$part1 = substr($raw, 0, $part2Pos);
$part2 = substr($raw, $part2Pos);

function narrationText(string $section): string
{
    $lines = preg_split('/\R/', $section) ?: [];
    $spoken = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (preg_match('/^=+$/', $line) || preg_match('/^-+$/', $line)) {
            continue;
        }

        if (preg_match('/^\[\d{2}:\d{2}\s*.+\]$/', $line)) {
            continue;
        }

        if (preg_match('/^(Part|Step)\s+\d/', $line)) {
            continue;
        }

        $spoken[] = $line;
    }

    return implode("\n\n", $spoken) . "\n";
}

$outputs = [
    'user-flow-adam-script.txt' => narrationText($part1),
    'ad-epermit-flow-adam-script.txt' => narrationText($part2),
    'full-process-adam-script.txt' => narrationText($raw),
];

foreach ($outputs as $filename => $content) {
    file_put_contents($outputDir . '/' . $filename, $content);
}

echo "Prepared voiceover scripts:\n";
foreach (array_keys($outputs) as $filename) {
    echo "- {$outputDir}/{$filename}\n";
}

