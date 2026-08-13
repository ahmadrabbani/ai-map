<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bp_chat_brains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bp_application_id')->unique()->constrained('bp_applications')->cascadeOnDelete();
            $table->longText('learning_summary')->nullable();
            $table->json('memory_json')->nullable();
            $table->timestamp('last_learned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_chat_brains');
    }
};
