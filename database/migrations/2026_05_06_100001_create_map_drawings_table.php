<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_drawings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->string('original_file_path');
            $table->string('dxf_file_path')->nullable();
            $table->string('status')->default('uploaded');
            $table->string('mapping_status')->nullable();
            $table->string('validation_status')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_drawings');
    }
};

