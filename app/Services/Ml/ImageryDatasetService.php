<?php

namespace App\Services\Ml;

use App\Models\BpApplication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ImageryDatasetService
{
    public function collect(int $limit = 200, bool $downloadStatic = false): array
    {
        $rows = BpApplication::query()
            ->whereNotNull('plot_data_json')
            ->latest('id')
            ->limit($limit)
            ->get();

        $stamp = now()->format('Ymd_His');
        $manifestPath = "ml/imagery/datasets/manifest_{$stamp}.jsonl";
        $records = 0;
        $images = 0;
        $lines = [];

        foreach ($rows as $app) {
            $sel = is_array(data_get($app->plot_data_json, 'map_selection')) ? (array) data_get($app->plot_data_json, 'map_selection') : [];
            $lat = data_get($sel, 'lat');
            $lng = data_get($sel, 'lng');
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }

            $entry = [
                'application_id' => $app->id,
                'application_number' => $app->application_number,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'formatted_address' => data_get($sel, 'formatted_address'),
                'plot_number' => data_get($sel, 'plot_number'),
                'scheme' => data_get($sel, 'scheme'),
                'phase' => data_get($sel, 'phase'),
                'block' => data_get($sel, 'block'),
                'plot_ref' => data_get($sel, 'plot_ref'),
                'site_signal' => data_get($sel, 'site_signal'),
                'geocode_json_path' => data_get($sel, 'geocode_json_path'),
                'label' => null,
                'label_source' => null,
                'created_at' => now()->toIso8601String(),
            ];

            if ($downloadStatic) {
                $imgPath = $this->downloadStaticImage((float) $lat, (float) $lng, (string) $app->application_number);
                if ($imgPath) {
                    $entry['image_path'] = $imgPath;
                    $images++;
                }
            }

            $lines[] = json_encode($entry, JSON_UNESCAPED_SLASHES);
            $records++;
        }

        Storage::disk('local')->put($manifestPath, implode("\n", $lines));

        return [
            'manifest_path' => $manifestPath,
            'records' => $records,
            'images_downloaded' => $images,
        ];
    }

    private function downloadStaticImage(float $lat, float $lng, string $applicationNumber): ?string
    {
        $key = (string) config('services.google.maps_api_key', '');
        if ($key === '') {
            return null;
        }

        $zoom = (int) config('ml.imagery.static_zoom', 20);
        $size = (string) config('ml.imagery.static_size', '512x512');
        $maptype = (string) config('ml.imagery.static_maptype', 'satellite');

        $url = 'https://maps.googleapis.com/maps/api/staticmap';
        $query = [
            'center' => $lat . ',' . $lng,
            'zoom' => $zoom,
            'size' => $size,
            'maptype' => $maptype,
            'format' => 'png',
            'markers' => 'color:red|' . $lat . ',' . $lng,
            'key' => $key,
        ];

        $resp = Http::timeout(20)->get($url, $query);
        if (! $resp->successful()) {
            return null;
        }

        $path = 'ml/imagery/images/' . now()->format('Y/m') . '/' . $applicationNumber . '_' . md5($lat . ',' . $lng) . '.png';
        Storage::disk('local')->put($path, $resp->body());

        return $path;
    }
}
