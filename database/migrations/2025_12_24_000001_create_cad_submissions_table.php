<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename')->nullable();
            $table->string('stored_dwg_path');
            $table->string('stored_dxf_path')->nullable();
            $table->string('overlay_pdf_path')->nullable();
            $table->string('ruleset_key')->default('5_marla_residential');
            $table->json('analysis_result')->nullable(); // full JSON returned by python
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_submissions');
    }
};
