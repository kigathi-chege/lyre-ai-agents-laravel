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
        $templatesTable = $prefix.'prompt_templates';

        if (!Schema::hasTable($templatesTable)) {
            Schema::create($templatesTable, function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->longText('content');
                $table->json('variables')->nullable();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['is_default', 'is_active']);
            });
        }

        if (Schema::hasTable($agentsTable)) {
            Schema::table($agentsTable, function (Blueprint $table) use ($templatesTable) {
                if (!Schema::hasColumn($table->getTable(), 'prompt_template_id')) {
                    $table->unsignedBigInteger('prompt_template_id')->nullable()->after('instructions');
                    $table->index('prompt_template_id');
                    $table->foreign('prompt_template_id')
                        ->references('id')
                        ->on($templatesTable)
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');

        $agentsTable = $prefix.'agents';
        $templatesTable = $prefix.'prompt_templates';

        if (Schema::hasTable($agentsTable)) {
            Schema::table($agentsTable, function (Blueprint $table) {
                if (Schema::hasColumn($table->getTable(), 'prompt_template_id')) {
                    $table->dropForeign(['prompt_template_id']);
                    $table->dropIndex(['prompt_template_id']);
                    $table->dropColumn('prompt_template_id');
                }
            });
        }

        Schema::dropIfExists($templatesTable);
    }
};
