<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dxf_pattern_training_examples')) {
            return;
        }

        Schema::create('dxf_pattern_training_examples', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_plan_application_id');
            $table->unsignedBigInteger('legacy_bp_application_id')->nullable();
            $table->unsignedBigInteger('bp_ai_report_id')->nullable();
            $table->unsignedBigInteger('cad_submission_id')->nullable();
            $table->unsignedBigInteger('map_drawing_id')->nullable();
            $table->unsignedBigInteger('ad_status_log_id')->nullable();
            $table->foreign('building_plan_application_id', 'fk_dxf_train_app')
                ->references('id')
                ->on('building_plan_applications')
                ->cascadeOnDelete();
            $table->foreign('legacy_bp_application_id', 'fk_dxf_train_legacy')
                ->references('id')
                ->on('bp_applications')
                ->nullOnDelete();
            $table->foreign('bp_ai_report_id', 'fk_dxf_train_report')
                ->references('id')
                ->on('bp_ai_reports')
                ->nullOnDelete();
            $table->foreign('cad_submission_id', 'fk_dxf_train_cad')
                ->references('id')
                ->on('cad_submissions')
                ->nullOnDelete();
            $table->foreign('map_drawing_id', 'fk_dxf_train_drawing')
                ->references('id')
                ->on('map_drawings')
                ->nullOnDelete();
            $table->foreign('ad_status_log_id', 'fk_dxf_train_status')
                ->references('id')
                ->on('application_status_logs')
                ->nullOnDelete();
            $table->string('ai_recommendation', 120)->nullable()->index();
            $table->decimal('ai_confidence_score', 5, 2)->nullable();
            $table->string('ad_decision', 120)->nullable()->index();
            $table->string('ad_outcome', 80)->index();
            $table->string('ad_status', 80)->nullable()->index();
            $table->text('ad_remarks')->nullable();
            $table->json('dxf_pattern_profile_json')->nullable();
            $table->json('cad_confidence_assessment_json')->nullable();
            $table->json('rule_results_json')->nullable();
            $table->json('feature_snapshot_json')->nullable();
            $table->string('label_source', 80)->default('ad_epermit');
            $table->timestamp('captured_at')->nullable()->index();
            $table->timestamps();

            $table->unique('building_plan_application_id', 'dxf_pattern_training_examples_app_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dxf_pattern_training_examples');
    }
};
