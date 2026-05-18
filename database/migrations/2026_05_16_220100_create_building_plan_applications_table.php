<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('building_plan_applications')) {
            return;
        }

        Schema::create('building_plan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no', 32)->unique()->nullable();
            $table->foreignId('user_id')->constrained('applicants')->cascadeOnDelete();
            $table->string('applicant_name');
            $table->string('applicant_cnic', 20);
            $table->string('applicant_email')->nullable();
            $table->string('applicant_phone', 20)->nullable();
            $table->string('scheme', 120)->nullable();
            $table->string('phase', 120)->nullable();
            $table->string('block', 120)->nullable();
            $table->string('plot_ref', 120)->nullable();
            $table->string('selected_address', 500)->nullable();
            $table->string('plan_file_path')->nullable();
            $table->string('list_document_path')->nullable();
            $table->string('ownership_document_path')->nullable();
            $table->string('cnic_front_path')->nullable();
            $table->string('cnic_back_path')->nullable();
            $table->string('affidavit_path')->nullable();
            $table->string('status', 60)->default('Draft');
            $table->string('ai_status', 60)->default('Not Evaluated');
            $table->json('ai_report_json')->nullable();
            $table->string('qr_code_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('routed_to', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_plan_applications');
    }
};
