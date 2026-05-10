<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bp_ai_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bp_application_id')->constrained('bp_applications')->cascadeOnDelete();
            $table->string('analysis_status')->default('pending')->index();
            $table->string('ai_recommendation')->default('Needs Expert Review')->index();
            $table->decimal('ai_confidence_score', 5, 2)->nullable();
            $table->json('analysis_json')->nullable();
            $table->longText('report_markdown')->nullable();
            $table->longText('report_html')->nullable();
            $table->json('detected_layers_json')->nullable();
            $table->json('detected_entities_json')->nullable();
            $table->json('rule_results_json')->nullable();
            $table->json('warnings_json')->nullable();
            $table->json('expert_review_items_json')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_ai_reports');
    }
};
