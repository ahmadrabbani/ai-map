<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dxf_pattern_training_examples')) {
            return;
        }

        DB::statement('ALTER TABLE dxf_pattern_training_examples MODIFY building_plan_application_id BIGINT UNSIGNED NULL');

        try {
            DB::statement('ALTER TABLE dxf_pattern_training_examples ADD UNIQUE KEY dxf_pattern_training_examples_cad_unique (cad_submission_id)');
        } catch (\Throwable $e) {
            // Ignore if the index already exists.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dxf_pattern_training_examples')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE dxf_pattern_training_examples DROP INDEX dxf_pattern_training_examples_cad_unique');
        } catch (\Throwable $e) {
            // Ignore if the index does not exist.
        }

        DB::statement('ALTER TABLE dxf_pattern_training_examples MODIFY building_plan_application_id BIGINT UNSIGNED NOT NULL');
    }
};
