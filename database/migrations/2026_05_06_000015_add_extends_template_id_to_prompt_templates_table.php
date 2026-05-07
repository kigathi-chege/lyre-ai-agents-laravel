<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $templatesTable = $prefix.'prompt_templates';

        if (!Schema::hasTable($templatesTable)) {
            return;
        }

        if (!Schema::hasColumn($templatesTable, 'extends_template_id')) {
            Schema::table($templatesTable, function (Blueprint $table) use ($templatesTable) {
                $table->unsignedBigInteger('extends_template_id')->nullable()->after('content');
                $table->index('extends_template_id');
                $table->foreign('extends_template_id')
                    ->references('id')
                    ->on($templatesTable)
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $templatesTable = $prefix.'prompt_templates';

        if (!Schema::hasTable($templatesTable) || !Schema::hasColumn($templatesTable, 'extends_template_id')) {
            return;
        }

        Schema::table($templatesTable, function (Blueprint $table) {
            $table->dropForeign(['extends_template_id']);
            $table->dropIndex(['extends_template_id']);
            $table->dropColumn('extends_template_id');
        });
    }
};
