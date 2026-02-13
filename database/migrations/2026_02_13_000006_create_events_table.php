<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $agentsTable = $prefix.'agents';
        $conversationsTable = $prefix.'conversations';
        $agentRunsTable = $prefix.'agent_runs';
        $eventsTable = $prefix.'events';

        if (!Schema::hasTable($eventsTable)) {
            Schema::create($eventsTable, function (Blueprint $table) use ($agentsTable, $conversationsTable, $agentRunsTable) {
                $table->id();
                $table->string('event_name');
                $table->foreignId('agent_id')->nullable()->constrained($agentsTable)->nullOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained($conversationsTable)->nullOnDelete();
                $table->foreignId('agent_run_id')->nullable()->constrained($agentRunsTable)->nullOnDelete();
                $table->json('payload')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->timestamps();
                $table->index(['event_name', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        Schema::dropIfExists($prefix.'events');
    }
};
