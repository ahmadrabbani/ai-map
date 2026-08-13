<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outputDir = $root . '/demo-output';
$frameDir = $outputDir . '/frames';
$font = '/System/Library/Fonts/Supplemental/Arial.ttf';
$boldFont = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';

if (! extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD extension is required.\n");
    exit(1);
}

if (! is_file($font)) {
    fwrite(STDERR, "Font not found: {$font}\n");
    exit(1);
}

@mkdir($frameDir, 0775, true);

$slides = [
    [
        'title' => 'AI-Assisted Building Plan Approval',
        'eyebrow' => 'Lahore Development Authority | Executive Demo',
        'subtitle' => 'Digital submission, CAD compliance checks, satellite review, and AD ePermit decision support in one workflow.',
        'bullets' => ['Applicant portal', 'AI scrutiny report', 'Planner review', 'AD ePermit routing'],
        'kind' => 'hero',
        'duration' => 6,
    ],
    [
        'title' => 'The Operational Problem',
        'eyebrow' => 'Current approval bottlenecks',
        'subtitle' => 'Manual checks are slow, inconsistent, and hard to audit across CAD drawings, documents, and site evidence.',
        'bullets' => ['Repeated document follow-up', 'Manual setback and FAR measurement', 'Limited visibility for applicants', 'No single evidence trail'],
        'kind' => 'problem',
        'duration' => 6,
    ],
    [
        'title' => 'Applicant Digital Submission',
        'eyebrow' => 'Step 1',
        'subtitle' => 'Citizens register with CNIC, upload plans and required documents, select the plot location, and receive transparent tracking.',
        'bullets' => ['CNIC-based account', 'Plan and document upload', 'Geo-verification wizard', 'Application status tracking'],
        'kind' => 'portal',
        'duration' => 6,
    ],
    [
        'title' => 'AI Scrutiny and CAD Compliance',
        'eyebrow' => 'Step 2',
        'subtitle' => 'The system reads DWG/DXF drawings, detects plot and building footprints, and evaluates planning rules.',
        'bullets' => ['Setbacks: front, rear, left, right', 'Ground coverage and FAR', 'Storey detection', 'Overlay report for manual review'],
        'kind' => 'analysis',
        'duration' => 6,
    ],
    [
        'title' => 'AD ePermit Review Dashboard',
        'eyebrow' => 'Step 3',
        'subtitle' => 'Officers compare AI recommendations with their decision, verify satellite evidence, and record observations.',
        'bullets' => ['AI vs officer recommendation', 'Google satellite site review', 'CAD viewer and planner review', 'Observation, rejection, or approval'],
        'kind' => 'dashboard',
        'duration' => 6,
    ],
    [
        'title' => 'Decision, Audit Trail, and Integration',
        'eyebrow' => 'Outcome',
        'subtitle' => 'Approved cases can be pushed forward with logs, ZIP attachments, and integration-ready payloads.',
        'bullets' => ['Status lifecycle audit trail', 'QR-linked verification report', 'DFPS/internal push logs', 'Oracle ePermit sync support'],
        'kind' => 'outcome',
        'duration' => 6,
    ],
];

$navy = [5, 34, 76];
$teal = [19, 138, 136];
$gold = [240, 197, 93];
$white = [255, 255, 255];
$muted = [218, 229, 242];

function color($image, array $rgb): int
{
    return imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
}

function gradient($image, int $width, int $height, array $from, array $to): void
{
    for ($y = 0; $y < $height; $y++) {
        $ratio = $y / max(1, $height - 1);
        $r = (int) round($from[0] + ($to[0] - $from[0]) * $ratio);
        $g = (int) round($from[1] + ($to[1] - $from[1]) * $ratio);
        $b = (int) round($from[2] + ($to[2] - $from[2]) * $ratio);
        imageline($image, 0, $y, $width, $y, imagecolorallocate($image, $r, $g, $b));
    }
}

function roundedRect($image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function textBox($image, string $text, int $size, int $x, int $y, int $maxWidth, int $lineHeight, int $color, string $font): int
{
    $words = preg_split('/\s+/', $text) ?: [];
    $line = '';
    $currentY = $y;

    foreach ($words as $word) {
        $test = trim($line . ' ' . $word);
        $box = imagettfbbox($size, 0, $font, $test);
        $width = abs(($box[2] ?? 0) - ($box[0] ?? 0));

        if ($width > $maxWidth && $line !== '') {
            imagettftext($image, $size, 0, $x, $currentY, $color, $font, $line);
            $line = $word;
            $currentY += $lineHeight;
        } else {
            $line = $test;
        }
    }

    if ($line !== '') {
        imagettftext($image, $size, 0, $x, $currentY, $color, $font, $line);
        $currentY += $lineHeight;
    }

    return $currentY;
}

function drawHeader($image, array $slide, string $font, string $boldFont, array $colors): void
{
    [$navy, $teal, $gold, $white, $muted] = $colors;
    $whiteColor = color($image, $white);
    $mutedColor = color($image, $muted);
    $goldColor = color($image, $gold);

    roundedRect($image, 96, 80, 515, 134, 18, imagecolorallocatealpha($image, 255, 255, 255, 104));
    imagettftext($image, 22, 0, 126, 115, $goldColor, $boldFont, $slide['eyebrow']);
    textBox($image, $slide['title'], 58, 96, 238, 900, 70, $whiteColor, $boldFont);
    textBox($image, $slide['subtitle'], 26, 100, 410, 860, 38, $mutedColor, $font);
}

function drawBullets($image, array $bullets, int $x, int $y, string $font, array $colors): void
{
    [, $teal, $gold, $white, $muted] = $colors;
    $whiteColor = color($image, $white);
    $mutedColor = color($image, $muted);
    $goldColor = color($image, $gold);
    $tealColor = color($image, $teal);

    foreach ($bullets as $index => $bullet) {
        $top = $y + ($index * 90);
        roundedRect($image, $x, $top, $x + 690, $top + 64, 16, imagecolorallocatealpha($image, 255, 255, 255, 112));
        imagefilledellipse($image, $x + 34, $top + 32, 28, 28, $index % 2 === 0 ? $goldColor : $tealColor);
        imagettftext($image, 22, 0, $x + 70, $top + 41, $whiteColor, $font, $bullet);
        imagefilledrectangle($image, $x + 70, $top + 52, $x + 520, $top + 55, $mutedColor);
    }
}

function drawMockup($image, string $kind, string $font, string $boldFont, array $colors): void
{
    [$navy, $teal, $gold, $white, $muted] = $colors;
    $panel = imagecolorallocate($image, 248, 251, 255);
    $line = imagecolorallocate($image, 207, 219, 232);
    $dark = imagecolorallocate($image, 18, 39, 70);
    $tealColor = color($image, $teal);
    $goldColor = color($image, $gold);
    $mutedDark = imagecolorallocate($image, 72, 91, 118);

    roundedRect($image, 1055, 130, 1815, 900, 32, imagecolorallocatealpha($image, 0, 0, 0, 82));
    roundedRect($image, 1028, 110, 1788, 880, 32, $panel);
    imagefilledrectangle($image, 1028, 110, 1788, 190, $dark);
    imagettftext($image, 24, 0, 1072, 160, color($image, $white), $boldFont, 'Workflow Preview');

    if ($kind === 'hero') {
        imagettftext($image, 30, 0, 1090, 275, $dark, $boldFont, 'Submission to Decision');
        $labels = ['Register', 'Upload', 'AI Check', 'Officer Review', 'Approve'];
        foreach ($labels as $index => $label) {
            $cx = 1120 + ($index * 145);
            imagefilledellipse($image, $cx, 410, 74, 74, $index < 3 ? $tealColor : $goldColor);
            imagettftext($image, 18, 0, $cx - 38, 505, $dark, $boldFont, $label);
            if ($index < 4) {
                imagefilledrectangle($image, $cx + 42, 406, $cx + 103, 414, $line);
            }
        }
        roundedRect($image, 1120, 610, 1690, 735, 18, imagecolorallocate($image, 231, 247, 246));
        imagettftext($image, 24, 0, 1160, 668, $dark, $boldFont, 'Single evidence trail');
        imagettftext($image, 18, 0, 1160, 708, $mutedDark, $font, 'Documents, CAD checks, reports, logs');
        return;
    }

    if ($kind === 'problem') {
        $rows = ['Manual CAD measurements', 'Document completeness checks', 'Site condition verification', 'Decision audit history'];
        foreach ($rows as $i => $row) {
            $top = 260 + ($i * 120);
            roundedRect($image, 1110, $top, 1700, $top + 72, 14, imagecolorallocate($image, 255, 255, 255));
            imageline($image, 1145, $top + 50, 1660, $top + 50, $line);
            imagettftext($image, 21, 0, 1145, $top + 42, $dark, $boldFont, $row);
        }
        return;
    }

    if ($kind === 'portal') {
        $fields = ['Applicant CNIC', 'Plot / Scheme / Block', 'CAD / PDF Upload', 'Geo Location'];
        foreach ($fields as $i => $field) {
            $top = 250 + ($i * 95);
            roundedRect($image, 1090, $top, 1700, $top + 56, 12, imagecolorallocate($image, 255, 255, 255));
            imagettftext($image, 19, 0, 1124, $top + 37, $mutedDark, $font, $field);
            imagefilledrectangle($image, 1450, $top + 20, 1665, $top + 28, $line);
        }
        roundedRect($image, 1090, 690, 1700, 770, 18, $tealColor);
        imagettftext($image, 24, 0, 1230, 740, color($image, $white), $boldFont, 'Upload & Generate AI Report');
        return;
    }

    if ($kind === 'analysis') {
        $metrics = [['Front', 'Pass'], ['Rear', 'Pass'], ['Coverage', 'Review'], ['FAR', 'Pass']];
        foreach ($metrics as $i => $metric) {
            $x = 1090 + (($i % 2) * 305);
            $y = 260 + ((int) floor($i / 2) * 180);
            roundedRect($image, $x, $y, $x + 260, $y + 130, 18, imagecolorallocate($image, 255, 255, 255));
            imagettftext($image, 22, 0, $x + 28, $y + 45, $dark, $boldFont, $metric[0]);
            imagettftext($image, 30, 0, $x + 28, $y + 95, $metric[1] === 'Pass' ? $tealColor : $goldColor, $boldFont, $metric[1]);
        }
        imagerectangle($image, 1130, 660, 1640, 790, $dark);
        imageline($image, 1130, 790, 1380, 660, $tealColor);
        imageline($image, 1380, 660, 1640, 790, $goldColor);
        imagettftext($image, 18, 0, 1180, 835, $mutedDark, $font, 'Overlay PDF for planner validation');
        return;
    }

    if ($kind === 'dashboard') {
        $cards = ['AI Recommendation', 'Officer Decision', 'Comparison Status'];
        foreach ($cards as $i => $card) {
            $x = 1085 + ($i * 215);
            roundedRect($image, $x, 255, $x + 190, 420, 18, imagecolorallocate($image, 255, 255, 255));
            textBox($image, $card, 19, $x + 18, 305, 155, 28, $dark, $boldFont);
            imagettftext($image, 18, 0, $x + 18, 385, $i === 2 ? $goldColor : $tealColor, $boldFont, $i === 2 ? 'Review' : 'Approve');
        }
        roundedRect($image, 1090, 515, 1700, 775, 18, imagecolorallocate($image, 226, 239, 255));
        imagefilledellipse($image, 1395, 645, 108, 108, $tealColor);
        imagettftext($image, 22, 0, 1218, 735, $dark, $boldFont, 'Satellite site marking + CAD viewer');
        return;
    }

    $steps = ['AI report', 'Officer decision', 'DFPS / Oracle sync', 'Audit logs'];
    foreach ($steps as $i => $step) {
        $y = 270 + ($i * 120);
        imagefilledellipse($image, 1120, $y, 54, 54, $i < 3 ? $tealColor : $goldColor);
        imagettftext($image, 22, 0, 1175, $y + 8, $dark, $boldFont, $step);
        if ($i < 3) {
            imagefilledrectangle($image, 1116, $y + 32, 1124, $y + 85, $line);
        }
    }
}

function drawSlide(array $slide, int $index, string $path, string $font, string $boldFont, array $colors): void
{
    $image = imagecreatetruecolor(1920, 1080);
    imagesavealpha($image, true);
    gradient($image, 1920, 1080, [3, 23, 54], [15, 118, 110]);

    imagefilledellipse($image, 1600, 120, 720, 720, imagecolorallocatealpha($image, 255, 255, 255, 116));
    imagefilledellipse($image, 170, 960, 520, 520, imagecolorallocatealpha($image, 240, 197, 93, 106));
    imagefilledrectangle($image, 0, 995, 1920, 1080, imagecolorallocatealpha($image, 0, 0, 0, 75));

    drawHeader($image, $slide, $font, $boldFont, $colors);
    drawBullets($image, $slide['bullets'], 100, 580, $font, $colors);
    drawMockup($image, $slide['kind'], $font, $boldFont, $colors);

    imagettftext($image, 20, 0, 96, 1035, color($image, [218, 229, 242]), $font, 'Map AI Verification demo | Generated from repository workflow');
    imagettftext($image, 20, 0, 1710, 1035, color($image, [218, 229, 242]), $font, sprintf('%02d / %02d', $index + 1, 6));

    imagepng($image, $path);
    imagedestroy($image);
}

$narration = [];
$concat = '';

foreach ($slides as $index => $slide) {
    $framePath = sprintf('%s/slide_%02d.png', $frameDir, $index + 1);
    drawSlide($slide, $index, $framePath, $font, is_file($boldFont) ? $boldFont : $font, [$navy, $teal, $gold, $white, $muted]);
    $concat .= "file '" . str_replace("'", "'\\''", $framePath) . "'\n";
    $concat .= "duration {$slide['duration']}\n";
    $narration[] = sprintf("%d. %s: %s", $index + 1, $slide['title'], $slide['subtitle']);
}

$lastFramePath = sprintf('%s/slide_%02d.png', $frameDir, count($slides));
$concat .= "file '" . str_replace("'", "'\\''", $lastFramePath) . "'\n";

file_put_contents($outputDir . '/demo_concat.txt', $concat);
file_put_contents($outputDir . '/presenter_script.txt', implode(PHP_EOL . PHP_EOL, $narration) . PHP_EOL);

$videoPath = $outputDir . '/map-ai-verification-executive-demo.mp4';
$cmd = [
    'ffmpeg',
    '-y',
    '-f',
    'concat',
    '-safe',
    '0',
    '-i',
    $outputDir . '/demo_concat.txt',
    '-vf',
    'fps=30,format=yuv420p',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    $videoPath,
];

$escaped = implode(' ', array_map('escapeshellarg', $cmd));
passthru($escaped, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "FFmpeg failed with exit code {$exitCode}.\n");
    exit($exitCode);
}

echo "Generated: {$videoPath}\n";
echo "Presenter script: {$outputDir}/presenter_script.txt\n";

