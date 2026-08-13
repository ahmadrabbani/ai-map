<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('dxf_pattern_training_examples')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('dxf_pattern_training_examples', function (Blueprint $table) {
                $table->unsignedBigInteger('building_plan_application_id')->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE dxf_pattern_training_examples MODIFY building_plan_application_id BIGINT UNSIGNED NULL');
        }

        try {
            Schema::table('dxf_pattern_training_examples', function (Blueprint $table) {
                $table->unique('cad_submission_id', 'dxf_pattern_training_examples_cad_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if the index already exists.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dxf_pattern_training_examples')) {
            return;
        }

        Schema::table('dxf_pattern_training_examples', function (Blueprint $table) {
            $table->dropUnique('dxf_pattern_training_examples_cad_unique');
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('dxf_pattern_training_examples', function (Blueprint $table) {
                $table->unsignedBigInteger('building_plan_application_id')->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE dxf_pattern_training_examples MODIFY building_plan_application_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
