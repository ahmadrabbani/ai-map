<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CadRule;
use App\Services\RuleEngineService;

class RuleEngineTest extends TestCase
{
    public function test_min_room_area_rule()
    {
        $rule = new CadRule([
            'rule_code' => 'MIN_ROOM_AREA',
            'name' => 'Minimum room area',
            'entity_type' => 'ROOM',
            'operator' => '>=',
            'value' => 100,
            'unit' => 'SQ_FT',
            'severity' => 'ERROR',
            'active' => true,
        ]);

        $svc = new RuleEngineService();
        $this->assertTrue($svc->evaluateRule($rule, 120));
        $this->assertFalse($svc->evaluateRule($rule, 80));
        $this->assertTrue($svc->evaluateRule($rule, 100));
    }
}
