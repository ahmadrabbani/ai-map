<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_approval_applications', function (Blueprint $table) {
            $table->id();
            $table->string('applicant_name');
            $table->string('contact_number');
            $table->string('plot_number');
            $table->string('scheme')->nullable();
            $table->string('phase')->nullable();
            $table->string('block')->nullable();
            $table->string('plot_size_category')->default('5_marla');
            $table->decimal('plot_area_sqft', 12, 2)->nullable();
            $table->string('building_type')->default('residential');
            $table->string('ruleset')->default('residential_building_approval');
            $table->string('current_step')->nullable();
            $table->string('status')->default('draft');
            $table->json('final_report_json')->nullable();
            $table->string('final_report_pdf_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_approval_applications');
    }
};
