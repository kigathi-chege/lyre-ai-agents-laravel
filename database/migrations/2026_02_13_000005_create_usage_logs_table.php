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
        $usageLogsTable = $prefix.'usage_logs';

        Schema::create($usageLogsTable, function (Blueprint $table) use ($agentsTable, $conversationsTable, $agentRunsTable) {
            $table->id();
            $table->foreignId('agent_id')->nullable()->constrained($agentsTable)->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained($conversationsTable)->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained($agentRunsTable)->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('model');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 14, 8)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['model', 'created_at']);
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        Schema::dropIfExists($prefix.'usage_logs');
    }
};
