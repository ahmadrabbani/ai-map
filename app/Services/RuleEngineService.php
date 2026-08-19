<?php

namespace App\Services;

use App\Models\CadRule;
use App\Models\CadTag;

class RuleEngineService
{
    public function evaluateRule(CadRule $rule, $measuredValue)
    {
        $op = $rule->operator;
        $required = $rule->value;

        switch ($op) {
            case '>': return $measuredValue > $required;
            case '>=': return $measuredValue >= $required;
            case '<': return $measuredValue < $required;
            case '<=': return $measuredValue <= $required;
            case '==': return $measuredValue == $required;
            case '!=': return $measuredValue != $required;
            default: return false;
        }
    }

    public function validateTag(CadTag $tag, iterable $rules): array
    {
        $messages = [];
        foreach ($rules as $rule) {
            if (! $rule->active || ! $this->appliesTo($rule, $tag)) continue;
            if (! $tag->unit_confirmed) {
                $messages[] = [
                    'rule_code' => $rule->rule_code,
                    'status' => 'needs_review',
                    'severity' => 'WARNING',
                    'message' => 'Confirm the CAD unit and scale before accepting this measurement.',
                ];
                continue;
            }
            $measured = match (strtoupper((string) $rule->unit)) {
                'SQ_FT' => $tag->area_sq_ft,
                'SQ_M' => $tag->area_sq_m,
                default => $tag->attributes['measured_value'] ?? null,
            };
            if ($measured === null) continue;
            $passed = $this->evaluateRule($rule, (float) $measured);
            $messages[] = [
                'rule_code' => $rule->rule_code,
                'status' => $passed ? 'pass' : 'violation',
                'severity' => $rule->severity,
                'measured_value' => (float) $measured,
                'required_value' => (float) $rule->value,
                'unit' => $rule->unit,
                'message' => $passed
                    ? "{$rule->name}: compliant."
                    : "{$rule->name} violation: measured {$measured} {$rule->unit}; required {$rule->operator} {$rule->value} {$rule->unit}.",
            ];
        }
        return $messages;
    }

    private function appliesTo(CadRule $rule, CadTag $tag): bool
    {
        $ruleEntity = strtoupper((string) $rule->entity_type);
        $tagEntity = strtoupper((string) $tag->label_key);
        if ($ruleEntity === 'ROOM') {
            return in_array($tagEntity, ['ROOM', 'BEDROOM'], true);
        }
        return $ruleEntity === '' || $ruleEntity === $tagEntity;
    }
}
