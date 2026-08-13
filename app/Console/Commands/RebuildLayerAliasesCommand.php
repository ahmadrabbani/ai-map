<?php

namespace App\Console\Commands;

use App\Models\CadLabelMapping;
use App\Services\LayerAliasService;
use Illuminate\Console\Command;

class RebuildLayerAliasesCommand extends Command
{
    protected $signature = 'cad:rebuild-layer-aliases {--limit=0 : Limit rows for quick run}';
    protected $description = 'Rebuild layer aliases from expert-confirmed CAD label mappings';

    public function handle(LayerAliasService $aliasService): int
    {
        $query = CadLabelMapping::query()
            ->where('user_confirmed', true)
            ->with('entity')
            ->orderBy('id');

        $limit = (int) $this->option('limit');
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
        return self::SUCCESS;
    }
}
