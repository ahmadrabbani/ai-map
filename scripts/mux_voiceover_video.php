<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$video = $argv[1] ?? $root . '/demo-output/map-ai-verification-animated-overview-with-sound.mp4';
$audio = $argv[2] ?? $root . '/demo-output/voiceover/full-process-adam.mp3';
$output = $argv[3] ?? $root . '/demo-output/lda-ai-verification-adam-voiceover.mp4';

foreach (['video' => $video, 'audio' => $audio] as $label => $path) {
    if (! is_file($path)) {
        fwrite(STDERR, ucfirst($label) . " file not found: {$path}\n");
        exit(1);
    }
}

function durationSeconds(string $path): float
{
    $cmd = [
        'ffprobe',
        '-v',
        'error',
        '-show_entries',
        'format=duration',
        '-of',
        'default=noprint_wrappers=1:nokey=1',
        $path,
    ];

    $output = [];
    $exitCode = 0;
    exec(implode(' ', array_map('escapeshellarg', $cmd)), $output, $exitCode);

    if ($exitCode !== 0 || ! isset($output[0]) || ! is_numeric($output[0])) {
        throw new RuntimeException("Unable to read media duration: {$path}");
    }

    return (float) $output[0];
}

$videoDuration = durationSeconds($video);
$audioDuration = durationSeconds($audio);
@mkdir(dirname($output), 0775, true);

$cmd = [
    'ffmpeg',
    '-y',
];

if ($videoDuration < $audioDuration) {
    $cmd[] = '-stream_loop';
    $cmd[] = '-1';
}

array_push(
    $cmd,
    '-i',
    $video,
    '-i',
    $audio,
    '-map',
    '0:v:0',
    '-map',
    '1:a:0',
    '-c:v',
    'libx264',
    '-pix_fmt',
    'yuv420p',
    '-c:a',
    'aac',
    '-b:a',
    '160k',
    '-t',
    number_format($audioDuration, 3, '.', ''),
    '-shortest',
    $output
);

passthru(implode(' ', array_map('escapeshellarg', $cmd)), $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "FFmpeg failed with exit code {$exitCode}.\n");
    exit($exitCode);
}

echo "Generated voiceover video: {$output}\n";
echo "Video duration: " . number_format($audioDuration, 2) . " seconds\n";

