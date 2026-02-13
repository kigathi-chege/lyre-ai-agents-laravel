<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $rawPrefix = env('AI_AGENTS_TABLE_PREFIX', 'ai_agents');
        $prefix = $rawPrefix === '' ? '' : (str_ends_with($rawPrefix, '_') ? $rawPrefix : $rawPrefix.'_');
        $agentsTable = $prefix.'agents';

        if (!Schema::hasTable('gpts') || !Schema::hasTable($agentsTable)) {
            return;
        }

        $gpts = DB::table('gpts')->get();

        foreach ($gpts as $gpt) {
            DB::table($agentsTable)->updateOrInsert(
                ['name' => $gpt->name],
                [
                    'model' => 'gpt-4.1-mini',
                    'instructions' => $gpt->description,
                    'temperature' => $gpt->temperature ?? null,
                    'max_output_tokens' => null,
                    'metadata' => json_encode([
                        'migrated_from' => 'gpts',
                        'gpt_id' => $gpt->id,
                        'assistant_id' => $gpt->assistant_id ?? null,
                        'industry' => $gpt->industry ?? null,
                        'company_name' => $gpt->company_name ?? null,
                        'client_user_id' => $gpt->client_user_id ?? null,
                        'client_app' => $gpt->client_app ?? null,
                        'vector_store_id' => $gpt->vector_store_id ?? null,
                    ]),
                    'is_active' => (bool) ($gpt->is_active ?? true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (Schema::hasColumn('gpts', 'deprecated_at') === false) {
            Schema::table('gpts', function ($table) {
                $table->timestamp('deprecated_at')->nullable();
            });
        }

        DB::table('gpts')->whereNull('deprecated_at')->update(['deprecated_at' => now()]);
    }

    public function down(): void
    {
        // Forward-only migration by design.
    }
};
