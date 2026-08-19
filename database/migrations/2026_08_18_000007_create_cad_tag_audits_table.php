<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_tag_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_submission_id')->constrained('cad_submissions')->cascadeOnDelete();
            $table->foreignId('cad_tag_id')->nullable()->constrained('cad_tags')->nullOnDelete();
            $table->foreignId('cad_prediction_id')->nullable()->constrained('cad_predictions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action')->index();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_tag_audits');
    }
};
