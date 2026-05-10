<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $path = base_path('rules/layer_35.json');
        if (! is_file($path)) {
            $path = base_path('rules/layers.json');
        }
        if (! is_file($path)) {
            return;
        }

        $decoded = json_decode((string) File::get($path), true);
        $layers = is_array($decoded['layers'] ?? null) ? $decoded['layers'] : [];
        if (empty($layers)) {
            return;
        }

        $aliasToTag = [];
        foreach ($layers as $officialName => $def) {
            $tag = trim((string) data_get($def, 'tag', ''));
            if ($tag === '') {
                $tag = str_replace(' ', '_', $this->normalizeLayerName((string) $officialName));
            }
            if ($tag === '') {
                continue;
            }

            $aliases = [
                (string) $officialName,
                (string) data_get($def, 'description', ''),
                $tag,
                str_replace('_', ' ', $tag),
            ];
            foreach ($aliases as $alias) {
                $normalized = $this->normalizeLayerName((string) $alias);
                if ($normalized === '') {
                    continue;
                }
                $aliasToTag[$normalized] = $tag;
            }
        }

        $rows = DB::table('cad_label_mappings')
            ->select('id', 'cad_submission_id', 'cad_entity_id', 'label_key')
            ->get();
        foreach ($rows as $row) {
            $current = trim((string) $row->label_key);
            if ($current === '') {
                continue;
            }
            $normalized = $this->normalizeLayerName($current);
            $canonical = $aliasToTag[$normalized] ?? null;
            if (! $canonical) {
                Log::warning('CAD label mapping backfill: unresolved label key preserved', [
                    'mapping_id' => $row->id,
                    'label_key' => $current,
                ]);
                continue;
            }
            if ($canonical === $current) {
                continue;
            }

            $existsCanonical = DB::table('cad_label_mappings')
                ->where('cad_submission_id', $row->cad_submission_id)
                ->where('cad_entity_id', $row->cad_entity_id)
                ->where('label_key', $canonical)
                ->where('id', '!=', $row->id)
                ->exists();
            if ($existsCanonical) {
                Log::warning('CAD label mapping backfill: duplicate canonical mapping detected, preserving current row', [
                    'mapping_id' => $row->id,
                    'label_key' => $current,
                    'canonical_key' => $canonical,
                ]);
                continue;
            }

            DB::table('cad_label_mappings')
                ->where('id', $row->id)
                ->update([
                    'label_key' => $canonical,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // No-op. Previous display-name keys are lossy once canonicalized.
    }

    private function normalizeLayerName(string $layerName): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $layerName);
        $value = trim((string) $value);
        $value = preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $value);
        $value = preg_replace('/[-_]+/', ' ', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return strtolower(trim((string) $value));
    }
};
