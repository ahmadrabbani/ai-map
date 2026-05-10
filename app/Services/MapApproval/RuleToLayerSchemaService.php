<?php

namespace App\Services\MapApproval;

class RuleToLayerSchemaService
{
    private ?array $cached = null;

    public function load(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $path = config_path('map_approval/rule_to_layer_schema.json');
        if (! is_file($path)) {
            return $this->cached = [];
        }

        $decoded = json_decode(file_get_contents($path), true);
        $schema = is_array($decoded) ? $decoded : [];

        return $this->cached = $this->expandSemanticSourceLayersFromRules($schema);
    }

    public function layerDefinitions(): array
    {
        return $this->load()['layer_definitions'] ?? [];
    }

    public function semanticEntities(): array
    {
        return $this->load()['semantic_entities'] ?? [];
    }

    private function expandSemanticSourceLayersFromRules(array $schema): array
    {
        $layersPath = is_file(base_path('rules/layer_35.json'))
            ? base_path('rules/layer_35.json')
            : base_path('rules/layers.json');
        if (! is_file($layersPath)) {
            return $schema;
        }

        $layersDecoded = json_decode(file_get_contents($layersPath), true);
        $ruleLayers = is_array($layersDecoded['layers'] ?? null) ? $layersDecoded['layers'] : [];
        if (empty($ruleLayers)) {
            return $schema;
        }

        $tagToLayers = [];
        foreach ($ruleLayers as $layerName => $def) {
            $tag = trim((string) ($def['tag'] ?? ''));
            if ($tag === '') {
                continue;
            }
            $tagToLayers[$tag][] = (string) $layerName;
        }

        $semanticTagAliases = [
            'plot_boundary' => ['plot_boundary', 'plot_line', 'boundary_wall'],
            'ground_floor_covered_polygon' => ['external_walls'],
            'basement_covered_polygon' => ['external_walls'],
            'first_floor_covered_polygon' => ['external_walls'],
            'second_floor_covered_polygon' => ['external_walls'],
            'front_building_line' => ['front_building_line'],
            'setback_lines' => ['front_building_line', 'side_building_line', 'rear_building_line'],
            'ground_porch_polygon' => ['porch', 'ramp'],
            'ground_services_polygon' => ['services'],
            'first_floor_services_polygon' => ['services'],
            'second_floor_services_polygon' => ['services'],
            'annotations_and_dimensions' => ['dimensions', 'measurement_text', 'text_general', 'section_line'],
            'roof_mumty' => ['mumty'],
            'roof_parapet_wall' => ['parapet_wall'],
            'utilities' => ['sewer_line', 'water_tank', 'rain_water_tank'],
        ];

        $layerDefs = is_array($schema['layer_definitions'] ?? null) ? $schema['layer_definitions'] : [];
        $semantic = is_array($schema['semantic_entities'] ?? null) ? $schema['semantic_entities'] : [];

        foreach ($semantic as $semanticKey => $cfg) {
            $sourceLayers = is_array($cfg['source_layers'] ?? null) ? $cfg['source_layers'] : [];
            if (empty($sourceLayers)) {
                continue;
            }

            $tags = [];
            foreach ($sourceLayers as $layerName) {
                $tag = trim((string) ($layerDefs[$layerName]['tag'] ?? ''));
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
            $tags = array_values(array_unique($tags));
            foreach ($semanticTagAliases[$semanticKey] ?? [] as $aliasTag) {
                $tags[] = $aliasTag;
            }
            $tags = array_values(array_unique($tags));
            if (empty($tags)) {
                continue;
            }

            $expanded = $sourceLayers;
            foreach ($tags as $tag) {
                foreach (($tagToLayers[$tag] ?? []) as $layerName) {
                    $expanded[] = (string) $layerName;
                }
            }

            $semantic[$semanticKey]['source_layers'] = array_values(array_unique($expanded));
        }

        $schema['semantic_entities'] = $semantic;

        return $schema;
    }
}
