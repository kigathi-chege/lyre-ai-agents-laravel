<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $tableName = $prefix.'tool_usage_logs';
        $agentsTable = $prefix.'agents';
        $conversationsTable = $prefix.'conversations';
        $agentRunsTable = $prefix.'agent_runs';

        Schema::create($tableName, function (Blueprint $table) use ($agentsTable, $conversationsTable, $agentRunsTable) {
            $table->id();
            $table->foreignId('agent_id')->constrained($agentsTable)->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained($conversationsTable)->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained($agentRunsTable)->nullOnDelete();
            $table->string('tool_name');
            $table->string('tool_type')->nullable();
            $table->string('handler_type')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'tool_name']);
            $table->index(['agent_run_id']);
            $table->index(['success']);
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        Schema::dropIfExists($prefix.'tool_usage_logs');
    }
};

