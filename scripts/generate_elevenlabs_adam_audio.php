<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$input = $argv[1] ?? $root . '/demo-output/voiceover/full-process-adam-script.txt';
$output = $argv[2] ?? $root . '/demo-output/voiceover/full-process-adam.mp3';
$apiKey = getenv('ELEVENLABS_API_KEY') ?: '';
$voiceId = getenv('ELEVENLABS_VOICE_ID') ?: 'pNInz6obpgDQGcFmaJgB';
$modelId = getenv('ELEVENLABS_MODEL_ID') ?: 'eleven_multilingual_v2';

if ($apiKey === '') {
    fwrite(STDERR, "ELEVENLABS_API_KEY is required to generate Adam voiceover audio.\n");
    exit(1);
}

if (! is_file($input)) {
    fwrite(STDERR, "Input script not found: {$input}\n");
    exit(1);
}

$text = trim((string) file_get_contents($input));
if ($text === '') {
    fwrite(STDERR, "Input script is empty: {$input}\n");
    exit(1);
}

@mkdir(dirname($output), 0775, true);

$payload = json_encode([
    'text' => $text,
    'model_id' => $modelId,
    'voice_settings' => [
        'stability' => 0.48,
        'similarity_boost' => 0.78,
        'style' => 0.18,
        'use_speaker_boost' => true,
    ],
], JSON_THROW_ON_ERROR);

$url = "https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}?output_format=mp3_44100_128";
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Accept: audio/mpeg',
            'Content-Type: application/json',
            'xi-api-key: ' . $apiKey,
        ]),
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 120,
    ],
]);

$audio = file_get_contents($url, false, $context);
$statusLine = $http_response_header[0] ?? '';

if ($audio === false || ! str_contains($statusLine, '200')) {
    fwrite(STDERR, "ElevenLabs request failed: {$statusLine}\n");
    if (is_string($audio) && $audio !== '') {
        fwrite(STDERR, substr($audio, 0, 800) . "\n");
    }
    exit(1);
}

file_put_contents($output, $audio);
echo "Generated Adam voiceover: {$output}\n";

