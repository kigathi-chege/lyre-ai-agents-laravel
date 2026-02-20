<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $tableName = $prefix.'agent_tools';

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('name');
                $table->index('is_enabled');
            }
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $tableName = $prefix.'agent_tools';

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            if (Schema::hasColumn($table->getTable(), 'is_enabled')) {
                $table->dropIndex(['is_enabled']);
                $table->dropColumn('is_enabled');
            }
        });
    }
};
