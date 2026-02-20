<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $tableName = $prefix.'agents';

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'description')) {
                $table->string('description')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $tableName = $prefix.'agents';

        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            if (Schema::hasColumn($table->getTable(), 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};

