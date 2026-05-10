<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class LayerGuidelineService
{
    public function loadGuidelines(): array
    {
        $path = 'rules/layer_guidelines.json';

        if (! Storage::disk('local')->exists($path)) {
            return [
                'required_layers' => [],
                'optional_layers' => [],
            ];
        }

        $decoded = json_decode(Storage::disk('local')->get($path), true);

        return is_array($decoded) ? $decoded : [
            'required_layers' => [],
            'optional_layers' => [],
        ];
    }

    public function allLayers(): array
    {
        $guidelines = $this->loadGuidelines();

        return array_values(array_merge(
            $guidelines['required_layers'] ?? [],
            $guidelines['optional_layers'] ?? []
        ));
    }

    public function aliasesMap(): array
    {
        $map = [];

        foreach ($this->allLayers() as $layer) {
            $canonical = $layer['name'] ?? null;
            if (! $canonical) {
                continue;
            }

            $map[strtolower($canonical)] = $canonical;
            foreach (($layer['aliases'] ?? []) as $alias) {
                $map[strtolower((string) $alias)] = $canonical;
            }
        }

        return $map;
    }

    public function summaryTable(): array
    {
        return array_map(function (array $layer) {
            return [
                'name' => $layer['name'] ?? null,
                'description' => $layer['description'] ?? null,
                'required' => (bool) ($layer['required'] ?? false),
                'purpose' => $layer['purpose'] ?? null,
                'aliases' => $layer['aliases'] ?? [],
            ];
        }, $this->allLayers());
    }
}
