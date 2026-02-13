<?php

namespace Lyre\AiAgents\Services;

class CostCalculator
{
    public function calculate(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = config("ai-agents.pricing.$model");

        if (!$pricing) {
            return 0.0;
        }

        $promptCost = ($promptTokens / 1_000_000) * (float) ($pricing['prompt_per_million'] ?? 0);
        $completionCost = ($completionTokens / 1_000_000) * (float) ($pricing['completion_per_million'] ?? 0);

        return round($promptCost + $completionCost, 8);
    }
}
