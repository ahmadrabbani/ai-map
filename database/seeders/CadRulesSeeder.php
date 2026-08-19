<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CadRule;

class CadRulesSeeder extends Seeder
{
    public function run()
    {
        CadRule::updateOrCreate([
            'rule_code' => 'MIN_ROOM_AREA'
        ], [
            'name' => 'Minimum room area',
            'entity_type' => 'ROOM',
            'operator' => '>=',
            'value' => 100,
            'unit' => 'SQ_FT',
            'severity' => 'ERROR',
            'active' => true,
        ]);
    }
}
