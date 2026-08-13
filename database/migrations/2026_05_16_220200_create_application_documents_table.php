<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('application_documents')) {
            return;
        }

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('building_plan_applications')->cascadeOnDelete();
            $table->string('document_type', 80);
            $table->string('file_path');
            $table->string('mime_type', 120)->nullable();
            $table->string('validation_status', 40)->default('needs_review');
            $table->text('validation_message')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');
    }
};
