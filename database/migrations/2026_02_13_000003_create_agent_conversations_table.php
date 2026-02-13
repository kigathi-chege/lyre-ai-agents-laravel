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
        $conversationMessagesTable = $prefix.'conversation_messages';

        Schema::create($conversationsTable, function (Blueprint $table) use ($agentsTable) {
            $table->id();
            $table->foreignId('agent_id')->nullable()->constrained($agentsTable)->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('external_id')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create($conversationMessagesTable, function (Blueprint $table) use ($conversationsTable, $agentsTable) {
            $table->id();
            $table->foreignId('conversation_id')->constrained($conversationsTable)->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained($agentsTable)->nullOnDelete();
            $table->string('role', 32)->index();
            $table->json('content');
            $table->string('tool_name')->nullable();
            $table->json('tool_arguments')->nullable();
            $table->json('tool_result')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 14, 8)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        Schema::dropIfExists($prefix.'conversation_messages');
        Schema::dropIfExists($prefix.'conversations');
    }
};
