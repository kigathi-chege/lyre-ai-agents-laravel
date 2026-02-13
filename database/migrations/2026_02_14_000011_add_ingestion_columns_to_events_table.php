<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $eventsTable = $prefix.'events';

        if (!Schema::hasTable($eventsTable)) {
            return;
        }

        Schema::table($eventsTable, function (Blueprint $table) use ($eventsTable) {
            if (!Schema::hasColumn($eventsTable, 'dedupe_key')) {
                $table->string('dedupe_key', 191)->nullable()->after('event_name')->index();
            }
            if (!Schema::hasColumn($eventsTable, 'status')) {
                $table->string('status', 32)->default('pending')->after('metadata')->index();
            }
            if (!Schema::hasColumn($eventsTable, 'attempts')) {
                $table->unsignedInteger('attempts')->default(0)->after('status');
            }
            if (!Schema::hasColumn($eventsTable, 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('attempts');
            }
            if (!Schema::hasColumn($eventsTable, 'processing_error')) {
                $table->text('processing_error')->nullable()->after('processed_at');
            }
        });

        Schema::table($eventsTable, function (Blueprint $table) use ($eventsTable) {
            if (Schema::hasColumn($eventsTable, 'dedupe_key')) {
                $table->unique('dedupe_key');
            }
        });
    }

    public function down(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $eventsTable = $prefix.'events';

        if (!Schema::hasTable($eventsTable)) {
            return;
        }

        Schema::table($eventsTable, function (Blueprint $table) use ($eventsTable) {
            if (Schema::hasColumn($eventsTable, 'dedupe_key')) {
                $table->dropUnique($eventsTable.'_dedupe_key_unique');
                $table->dropColumn('dedupe_key');
            }
            if (Schema::hasColumn($eventsTable, 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn($eventsTable, 'attempts')) {
                $table->dropColumn('attempts');
            }
            if (Schema::hasColumn($eventsTable, 'processed_at')) {
                $table->dropColumn('processed_at');
            }
            if (Schema::hasColumn($eventsTable, 'processing_error')) {
                $table->dropColumn('processing_error');
            }
        });
    }
};
