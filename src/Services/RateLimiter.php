<?php

namespace Lyre\AiAgents\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RateLimiter
{
    public function assertAllowed(array $scope): void
    {
        if (!config('ai-agents.rate_limit.enabled', true)) {
            return;
        }

        $window = (int) config('ai-agents.rate_limit.window_seconds', 60);
        $max = (int) config('ai-agents.rate_limit.max_requests', 30);

        $key = 'ai_agents_rate_limit:'.sha1(json_encode($scope));
        $now = time();

        $bucket = Cache::get($key, []);
        $bucket = array_values(array_filter($bucket, fn ($t) => ($now - $t) < $window));

        if (count($bucket) >= $max) {
            throw new RuntimeException('Rate limit exceeded for scope '.json_encode($scope));
        }

        $bucket[] = $now;
        Cache::put($key, $bucket, $window);
    }
}
