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

        if (!Schema::hasTable($agentsTable)) {
            return;
        }

        if (!Schema::hasColumn($agentsTable, 'openai_api_key')) {
            Schema::table($agentsTable, function (Blueprint $table) {
                $table->text('openai_api_key')->nullable()->after('instructions');
            });
        }
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $agentsTable = $prefix.'agents';

        if (!Schema::hasTable($agentsTable) || !Schema::hasColumn($agentsTable, 'openai_api_key')) {
            return;
        }

        Schema::table($agentsTable, function (Blueprint $table) {
            $table->dropColumn('openai_api_key');
        });
    }
};

