<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bp_review_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bp_application_id')->constrained('bp_applications')->cascadeOnDelete();
            $table->string('actor_type', 50)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('remarks')->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_review_logs');
    }
};
