<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bp_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bp_application_id')->constrained('bp_applications')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('message');
            $table->json('context_json')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['bp_application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bp_chat_messages');
    }
};
