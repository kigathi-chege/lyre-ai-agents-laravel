<?php

namespace Lyre\AiAgents;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Lyre\AiAgents\Services\AgentManager;
use Lyre\AiAgents\Services\AgentRunner;
use Lyre\AiAgents\Services\ConversationStore;
use Lyre\AiAgents\Services\CostCalculator;
use Lyre\AiAgents\Services\InboundEventProcessor;
use Lyre\AiAgents\Services\OpenAIClient;
use Lyre\AiAgents\Services\PromptTemplateResolver;
use Lyre\AiAgents\Services\RateLimiter;
use Lyre\AiAgents\Services\ToolRegistry;
use Lyre\AiAgents\Services\UsageTracker;

class AiAgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-agents.php', 'ai-agents');

        $this->app->singleton(OpenAIClient::class);
        $this->app->singleton(ToolRegistry::class);
        $this->app->singleton(CostCalculator::class);
        $this->app->singleton(UsageTracker::class);
        $this->app->singleton(ConversationStore::class);
        $this->app->singleton(RateLimiter::class);
        $this->app->singleton(PromptTemplateResolver::class);
        $this->app->singleton(InboundEventProcessor::class);
        $this->app->singleton(AgentRunner::class);
        $this->app->singleton(AgentManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/ai-agents.php' => config_path('ai-agents.php'),
        ], 'ai-agents-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'ai-agents-migrations');

        Route::middleware(['api'])
            ->prefix('api/ai-agents')
            ->group(__DIR__.'/../routes/api.php');
    }
}
