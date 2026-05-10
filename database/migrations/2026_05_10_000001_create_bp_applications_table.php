<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bp_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_number')->unique();
            $table->string('status')->default('Draft')->index();
            $table->string('applicant_name')->nullable();
            $table->string('applicant_email')->nullable();
            $table->string('applicant_phone')->nullable();
            $table->string('uploaded_file_name');
            $table->string('uploaded_file_path');
            $table->string('uploaded_file_type', 20)->nullable();
            $table->unsignedBigInteger('uploaded_file_size')->nullable();
            $table->string('qr_token')->unique();
            $table->text('verification_url');
            $table->text('qr_code_url')->nullable();
            $table->foreignId('cad_submission_id')->nullable()->constrained('cad_submissions')->nullOnDelete();
            $table->foreignId('map_drawing_id')->nullable()->constrained('map_drawings')->nullOnDelete();
            $table->timestamp('submitted_to_ad_at')->nullable();
            $table->timestamp('forwarded_to_ddtp_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_applications');
    }
};
