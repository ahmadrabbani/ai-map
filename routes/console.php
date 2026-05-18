<?php

use App\Services\Ml\ImageryDatasetService;
use App\Models\BpImageryLabel;
use App\Models\CadLabelMapping;
use App\Services\LayerAliasService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bp:imagery-dataset:collect {--limit=200} {--download-static}', function (ImageryDatasetService $service) {
    $limit = max(1, (int) $this->option('limit'));
    $download = (bool) $this->option('download-static');

    $result = $service->collect($limit, $download);

    $this->info('Imagery dataset manifest created.');
    $this->line('Manifest: ' . $result['manifest_path']);
    $this->line('Records: ' . $result['records']);
    $this->line('Images downloaded: ' . $result['images_downloaded']);
})->purpose('Collect imagery dataset manifest from saved map selections');

Artisan::command('bp:imagery-labels:template {--manifest=} {--out=storage/app/ml/imagery/labels.csv}', function () {
    $manifestOption = (string) $this->option('manifest');
    $out = (string) $this->option('out');

    $manifestPath = $manifestOption;
    if ($manifestPath === '') {
        $dir = Storage::disk('local')->path('ml/imagery/datasets');
        $candidates = glob($dir . '/manifest_*.jsonl') ?: [];
        rsort($candidates);
        $manifestPath = $candidates[0] ?? '';
    }

    if ($manifestPath === '' || ! is_file($manifestPath)) {
        $this->error('No manifest found. Run: php artisan bp:imagery-dataset:collect --limit=500 --download-static');
        return 1;
    }

    $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $rows = ["application_id,label"];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (! is_array($row)) {
            continue;
        }
        $appId = (int) ($row['application_id'] ?? 0);
        if ($appId > 0) {
            $rows[] = $appId . ',';
        }
    }

    $absOut = str_starts_with($out, '/')
        ? $out
        : base_path($out);
    if (! is_dir(dirname($absOut))) {
        mkdir(dirname($absOut), 0777, true);
    }
    file_put_contents($absOut, implode(PHP_EOL, $rows) . PHP_EOL);

    $this->info('Labels template created.');
    $this->line('Manifest: ' . $manifestPath);
    $this->line('CSV: ' . $absOut);
    return 0;
})->purpose('Generate labels CSV template from latest imagery manifest');

Artisan::command('bp:imagery-labels:export-db {--out=storage/app/ml/imagery/labels.csv}', function () {
    $out = (string) $this->option('out');
    $absOut = str_starts_with($out, '/')
        ? $out
        : base_path($out);

    $labels = BpImageryLabel::query()
        ->select(['bp_application_id', 'label'])
        ->orderBy('bp_application_id')
        ->get();

    if ($labels->isEmpty()) {
        $this->error('No imagery labels found in DB. Save labels from AD ePermit review first.');
        return 1;
    }

    if (! is_dir(dirname($absOut))) {
        mkdir(dirname($absOut), 0777, true);
    }

    $rows = ['application_id,label'];
    foreach ($labels as $label) {
        $rows[] = $label->bp_application_id . ',' . strtolower((string) $label->label);
    }
    file_put_contents($absOut, implode(PHP_EOL, $rows) . PHP_EOL);

    $this->info('DB labels exported.');
    $this->line('CSV: ' . $absOut);
    $this->line('Rows: ' . $labels->count());
    return 0;
})->purpose('Export AD imagery labels from DB to training CSV');

Artisan::command('bp:imagery-model:train {labelsCsv} {--manifest=} {--out=}', function () {
    $labels = (string) $this->argument('labelsCsv');
    $manifest = (string) $this->option('manifest');
    $out = (string) ($this->option('out') ?: config('ml.imagery.model_path', base_path('storage/app/ml/imagery/imagery_signal_model.json')));
    $python = (string) config('ml.imagery.python_bin', 'python3');
    $script = (string) config('ml.imagery.train_script', base_path('scripts/ml/train_imagery_signal.py'));

    if (! is_file($script)) {
        $this->error('Training script not found: ' . $script);
        return 1;
    }

    $cmd = [$python, $script, '--labels-csv', $labels, '--out', $out];
    if ($manifest !== '') {
        $cmd[] = '--manifest';
        $cmd[] = $manifest;
    }

    $run = Process::timeout(240)->run($cmd);
    if (! $run->successful()) {
        $this->error('Training failed: ' . $run->errorOutput());
        return 1;
    }

    $this->info('Imagery model training completed.');
    $this->line(trim($run->output()));
    return 0;
})->purpose('Train imagery model scaffold from labels CSV');


Artisan::command('cad:rebuild-layer-aliases {--limit=0}', function (LayerAliasService $aliasService) {
    $query = CadLabelMapping::query()
        ->where('user_confirmed', true)
        ->with('entity')
        ->orderBy('id');

    $limit = max(0, (int) $this->option('limit'));
    if ($limit > 0) {
        $query->limit($limit);
    }

    $rows = $query->get();
    $count = 0;
    foreach ($rows as $row) {
        $aliasService->learnFromEntityMapping($row->entity, (string) $row->label_key, 'rebuild_command');
        $count++;
    }

    $this->info("Layer aliases rebuilt from {$count} mappings.");
    return 0;
})->purpose('Rebuild layer aliases from expert-confirmed CAD label mappings');
