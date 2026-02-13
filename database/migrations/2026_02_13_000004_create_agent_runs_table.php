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

        Schema::create($agentRunsTable, function (Blueprint $table) use ($agentsTable, $conversationsTable) {
            $table->id();
            $table->foreignId('agent_id')->constrained($agentsTable)->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained($conversationsTable)->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('error_payload')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 14, 8)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['agent_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        Schema::dropIfExists($prefix.'agent_runs');
    }
};
