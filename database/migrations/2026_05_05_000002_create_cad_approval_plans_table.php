<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_approval_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_approval_application_id')
                ->constrained('cad_approval_applications')
                ->cascadeOnDelete();
            $table->foreignId('cad_submission_id')
                ->nullable()
                ->constrained('cad_submissions')
                ->nullOnDelete();
            $table->string('floor_type');
            $table->string('label')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_uploaded')->default(false);
            $table->string('status')->default('pending');
            $table->string('original_file_path')->nullable();
            $table->string('overlay_pdf_path')->nullable();
            $table->string('drawing_pdf_path')->nullable();
            $table->json('analysis_result')->nullable();
            $table->text('expert_notes')->nullable();
            $table->timestamps();

            $table->unique(['cad_approval_application_id', 'floor_type'], 'cad_approval_plan_floor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_approval_plans');
    }
};
