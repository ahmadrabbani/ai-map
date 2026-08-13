<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outputDir = $root . '/demo-output';
$frameDir = $outputDir . '/screenshot-demo-frames';
$videoPath = $outputDir . '/map-ai-verification-animated-overview-with-sound.mp4';
$scriptPath = $outputDir . '/animated_overview_script.txt';
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

$sourceImages = [
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.04.31 PM.png',
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.05.20 PM.png',
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.05.33 PM.png',
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.05.50 PM.png',
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.05.54 PM.png',
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.05.57 PM.png',
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.06.09 PM.png',
    '/Users/ditit/Desktop/Screenshot 2026-06-05 at 6.06.13 PM.png',
];

foreach ($sourceImages as $sourceImage) {
    if (! is_file($sourceImage)) {
        fwrite(STDERR, "Missing screenshot: {$sourceImage}\n");
        exit(1);
    }
}

$scenes = [
    [
        'image' => $sourceImages[7],
        'title' => 'Digital plan submission starts with CAD upload',
        'caption' => 'Applicants upload DWG, DXF, CAD or PDF files. The workflow starts collecting evidence immediately.',
        'tag' => 'Applicant Portal',
        'duration' => 4.2,
        'zoomStart' => 1.00,
        'zoomEnd' => 1.08,
        'focusX' => 0.50,
        'focusY' => 0.28,
    ],
    [
        'image' => $sourceImages[0],
        'title' => 'Pre-scrutiny reads plan confidence and mappings',
        'caption' => 'The system evaluates CAD text, dimensions and confidence signals before moving to the next step.',
        'tag' => 'AI Pre-Scrutiny',
        'duration' => 4.2,
        'zoomStart' => 1.08,
        'zoomEnd' => 1.15,
        'focusX' => 0.50,
        'focusY' => 0.46,
    ],
    [
        'image' => $sourceImages[1],
        'title' => 'Property location is verified on map',
        'caption' => 'Geo verification confirms the plot signal and reduces location ambiguity before review.',
        'tag' => 'Geo Signal',
        'duration' => 4.3,
        'zoomStart' => 1.02,
        'zoomEnd' => 1.11,
        'focusX' => 0.50,
        'focusY' => 0.48,
    ],
    [
        'image' => $sourceImages[2],
        'title' => 'Applicant data is auto-filled where possible',
        'caption' => 'Applicant identity, contact information and property context are captured in a structured workflow.',
        'tag' => 'Structured Intake',
        'duration' => 3.8,
        'zoomStart' => 1.00,
        'zoomEnd' => 1.07,
        'focusX' => 0.54,
        'focusY' => 0.32,
    ],
    [
        'image' => $sourceImages[4],
        'title' => 'Required documents are collected before scrutiny',
        'caption' => 'CNIC, ownership document and supporting files are gathered with a clear submission checklist.',
        'tag' => 'Document Checklist',
        'duration' => 4.0,
        'zoomStart' => 1.00,
        'zoomEnd' => 1.08,
        'focusX' => 0.55,
        'focusY' => 0.34,
    ],
    [
        'image' => $sourceImages[5],
        'title' => 'AI scrutiny produces a measurable report',
        'caption' => 'The report shows confidence, extracted dimensions, text-driven measurements and rule results.',
        'tag' => 'AI Report',
        'duration' => 5.3,
        'zoomStart' => 1.00,
        'zoomEnd' => 1.13,
        'focusX' => 0.50,
        'focusY' => 0.48,
    ],
    [
        'image' => $sourceImages[6],
        'title' => 'Expert review closes gaps in CAD interpretation',
        'caption' => 'Layer viewer, rule assistant and expert labels help validate complex CAD drawings before final action.',
        'tag' => 'Planner + Expert Review',
        'duration' => 4.8,
        'zoomStart' => 1.00,
        'zoomEnd' => 1.09,
        'focusX' => 0.53,
        'focusY' => 0.48,
    ],
    [
        'image' => $sourceImages[3],
        'title' => 'AD ePermit sees AI and officer decisions side by side',
        'caption' => 'Reviewers inspect application data, CAD analysis, satellite evidence, attachments and push status in one dashboard.',
        'tag' => 'AD ePermit Review',
        'duration' => 5.2,
        'zoomStart' => 1.00,
        'zoomEnd' => 1.08,
        'focusX' => 0.35,
        'focusY' => 0.44,
    ],
    [
        'image' => $sourceImages[5],
        'title' => 'What we are building',
        'caption' => 'A transparent approval pipeline: digital intake, AI-assisted scrutiny, human validation, audit trail and system integration.',
        'tag' => 'End-to-End Overview',
        'duration' => 4.4,
        'zoomStart' => 1.11,
        'zoomEnd' => 1.00,
        'focusX' => 0.50,
        'focusY' => 0.18,
    ],
];

$fps = 30;
$width = 1920;
$height = 1080;
$navy = [8, 28, 65];
$deepNavy = [5, 18, 42];
$teal = [18, 122, 112];
$gold = [245, 201, 91];
$white = [255, 255, 255];
$light = [230, 239, 250];
$darkText = [18, 34, 62];

function gdColor(GdImage $image, array $rgb, int $alpha = 0): int
{
    return imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], $alpha);
}

function drawRoundedRect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
{
    imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
    imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
}

function drawTextBox(GdImage $image, string $text, int $size, int $x, int $y, int $maxWidth, int $lineHeight, int $color, string $font): int
{
    $words = preg_split('/\s+/', $text) ?: [];
    $line = '';
    $currentY = $y;

    foreach ($words as $word) {
        $test = trim($line . ' ' . $word);
        $box = imagettfbbox($size, 0, $font, $test);
        $lineWidth = abs(($box[2] ?? 0) - ($box[0] ?? 0));
        if ($lineWidth > $maxWidth && $line !== '') {
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

function ease(float $t): float
{
    return $t < 0.5 ? 4 * $t * $t * $t : 1 - pow(-2 * $t + 2, 3) / 2;
}

function loadPng(string $path): GdImage
{
    $image = imagecreatefrompng($path);
    if (! $image) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $image;
}

function drawBackground(GdImage $frame, int $width, int $height, array $deepNavy, array $teal): void
{
    for ($y = 0; $y < $height; $y++) {
        $ratio = $y / max(1, $height - 1);
        $r = (int) round($deepNavy[0] + ($teal[0] - $deepNavy[0]) * $ratio);
        $g = (int) round($deepNavy[1] + ($teal[1] - $deepNavy[1]) * $ratio);
        $b = (int) round($deepNavy[2] + ($teal[2] - $deepNavy[2]) * $ratio);
        imageline($frame, 0, $y, $width, $y, imagecolorallocate($frame, $r, $g, $b));
    }
    imagefilledellipse($frame, 1680, 120, 640, 640, gdColor($frame, [255, 255, 255], 116));
    imagefilledellipse($frame, 170, 1020, 500, 500, gdColor($frame, [245, 201, 91], 102));
}

function drawScreenshot(GdImage $frame, GdImage $source, array $scene, float $progress, int $width, int $height): void
{
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $baseW = 1460;
    $baseH = 822;
    $x = 230;
    $y = 138;
    $ease = ease($progress);
    $zoom = $scene['zoomStart'] + (($scene['zoomEnd'] - $scene['zoomStart']) * $ease);

    $cropW = (int) round($sourceWidth / $zoom);
    $cropH = (int) round($sourceHeight / $zoom);
    $cropX = (int) round(($sourceWidth - $cropW) * $scene['focusX']);
    $cropY = (int) round(($sourceHeight - $cropH) * $scene['focusY']);
    $cropX = max(0, min($sourceWidth - $cropW, $cropX));
    $cropY = max(0, min($sourceHeight - $cropH, $cropY));

    $scale = min($baseW / $cropW, $baseH / $cropH);
    $drawW = (int) round($cropW * $scale);
    $drawH = (int) round($cropH * $scale);
    $drawX = $x + (int) round(($baseW - $drawW) / 2);
    $drawY = $y + (int) round(($baseH - $drawH) / 2);

    drawRoundedRect($frame, $x - 26, $y - 26, $x + $baseW + 26, $y + $baseH + 26, 32, gdColor($frame, [0, 0, 0], 92));
    drawRoundedRect($frame, $x - 14, $y - 14, $x + $baseW + 14, $y + $baseH + 14, 28, gdColor($frame, [255, 255, 255], 6));
    imagecopyresampled($frame, $source, $drawX, $drawY, $cropX, $cropY, $drawW, $drawH, $cropW, $cropH);
}

function drawOverlay(GdImage $frame, array $scene, int $sceneIndex, int $sceneCount, float $progress, int $width, int $height, string $font, string $boldFont, array $palette): void
{
    [$navy, $teal, $gold, $white, $light, $darkText] = $palette;
    $slideIn = min(1.0, $progress / 0.22);
    $lowerY = (int) round(778 + ((1 - ease($slideIn)) * 180));

    imagefilledrectangle($frame, 0, 0, $width, 112, gdColor($frame, [0, 0, 0], 82));
    imagefilledrectangle($frame, 0, 0, $width, 112, gdColor($frame, $navy, 8));
    imagettftext($frame, 27, 0, 110, 68, gdColor($frame, $white), $boldFont, 'LDA AD ePermit + Map AI Verification');
    imagettftext($frame, 18, 0, 110, 96, gdColor($frame, $light), $font, 'AI-assisted building plan scrutiny and review workflow');

    $progressX1 = 1250;
    $progressY = 66;
    $progressWidth = 560;
    drawRoundedRect($frame, $progressX1, $progressY, $progressX1 + $progressWidth, $progressY + 12, 6, gdColor($frame, [255, 255, 255], 98));
    $filled = (int) round($progressWidth * (($sceneIndex + $progress) / $sceneCount));
    drawRoundedRect($frame, $progressX1, $progressY, $progressX1 + $filled, $progressY + 12, 6, gdColor($frame, $gold));
    imagettftext($frame, 18, 0, $progressX1, 46, gdColor($frame, $light), $font, sprintf('Scene %d of %d', $sceneIndex + 1, $sceneCount));

    drawRoundedRect($frame, 122, $lowerY, 1798, 1012, 26, gdColor($frame, [255, 255, 255], 5));
    drawRoundedRect($frame, 150, $lowerY + 28, 462, $lowerY + 84, 18, gdColor($frame, $gold));
    imagettftext($frame, 20, 0, 180, $lowerY + 64, gdColor($frame, $darkText), $boldFont, strtoupper($scene['tag']));
    drawTextBox($frame, $scene['title'], 37, 180, $lowerY + 145, 920, 46, gdColor($frame, $white), $boldFont);
    drawTextBox($frame, $scene['caption'], 25, 180, $lowerY + 202, 1210, 34, gdColor($frame, $light), $font);

    $metricX = 1298;
    $metrics = [
        ['Digital intake', 'CAD + documents'],
        ['AI assistance', 'Rules + confidence'],
        ['Human control', 'Officer decision'],
    ];
    foreach ($metrics as $i => $metric) {
        $x = $metricX + ($i * 190);
        drawRoundedRect($frame, $x, $lowerY + 116, $x + 160, $lowerY + 210, 16, gdColor($frame, [255, 255, 255], 103));
        imagettftext($frame, 17, 0, $x + 16, $lowerY + 154, gdColor($frame, $white), $boldFont, $metric[0]);
        imagettftext($frame, 14, 0, $x + 16, $lowerY + 184, gdColor($frame, $light), $font, $metric[1]);
    }

    $fade = $progress < 0.12 ? (int) round(127 * (1 - ($progress / 0.12))) : ($progress > 0.90 ? (int) round(127 * (($progress - 0.90) / 0.10)) : 0);
    if ($fade > 0) {
        imagefilledrectangle($frame, 0, 0, $width, $height, gdColor($frame, [0, 0, 0], min(120, $fade)));
    }
}

$sourceCache = [];
$frameNumber = 1;
$sceneText = [];

foreach ($scenes as $sceneIndex => $scene) {
    $sourceCache[$scene['image']] ??= loadPng($scene['image']);
    $source = $sourceCache[$scene['image']];
    $framesInScene = (int) round($scene['duration'] * $fps);
    $sceneText[] = sprintf(
        "%d. %s — %s",
        $sceneIndex + 1,
        $scene['title'],
        $scene['caption']
    );

    for ($i = 0; $i < $framesInScene; $i++) {
        $framePath = sprintf('%s/frame_%05d.png', $frameDir, $frameNumber);
        if (is_file($framePath)) {
            $frameNumber++;
            continue;
        }

        $progress = $framesInScene <= 1 ? 1.0 : $i / ($framesInScene - 1);
        $frame = imagecreatetruecolor($width, $height);
        imagesavealpha($frame, true);
        drawBackground($frame, $width, $height, $deepNavy, $teal);
        drawScreenshot($frame, $source, $scene, $progress, $width, $height);
        drawOverlay($frame, $scene, $sceneIndex, count($scenes), $progress, $width, $height, $font, is_file($boldFont) ? $boldFont : $font, [$navy, $teal, $gold, $white, $light, $darkText]);
        imagepng($frame, $framePath);
        imagedestroy($frame);
        $frameNumber++;
    }
}

foreach ($sourceCache as $source) {
    imagedestroy($source);
}

file_put_contents($scriptPath, implode(PHP_EOL . PHP_EOL, $sceneText) . PHP_EOL);

$duration = array_reduce($scenes, fn (float $carry, array $scene): float => $carry + $scene['duration'], 0.0);
$audioFilter = sprintf(
    '[1:a]volume=0.055,afade=t=in:st=0:d=1.8,afade=t=out:st=%.2f:d=2.2[a0];' .
    '[2:a]volume=0.032,afade=t=in:st=0:d=2.4,afade=t=out:st=%.2f:d=2.2[a1];' .
    '[3:a]volume=0.018,afade=t=in:st=1:d=2.0,afade=t=out:st=%.2f:d=2.0[a2];' .
    '[a0][a1][a2]amix=inputs=3:duration=longest,alimiter=limit=0.35[a]',
    max(0, $duration - 2.2),
    max(0, $duration - 2.2),
    max(0, $duration - 2.0)
);

$cmd = [
    'ffmpeg',
    '-y',
    '-framerate',
    (string) $fps,
    '-i',
    $frameDir . '/frame_%05d.png',
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=174:sample_rate=48000:duration=' . number_format($duration, 2, '.', ''),
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=261.63:sample_rate=48000:duration=' . number_format($duration, 2, '.', ''),
    '-f',
    'lavfi',
    '-i',
    'sine=frequency=392:sample_rate=48000:duration=' . number_format($duration, 2, '.', ''),
    '-filter_complex',
    $audioFilter,
    '-map',
    '0:v',
    '-map',
    '[a]',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-r',
    (string) $fps,
    '-c:a',
    'aac',
    '-b:a',
    '128k',
    '-shortest',
    $videoPath,
];

$escaped = implode(' ', array_map('escapeshellarg', $cmd));
passthru($escaped, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "FFmpeg failed with exit code {$exitCode}.\n");
    exit($exitCode);
}

echo "Generated: {$videoPath}\n";
echo "Presenter notes: {$scriptPath}\n";
