<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cad_approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cad_approval_application_id')
                ->constrained('cad_approval_applications')
                ->cascadeOnDelete();
            $table->foreignId('cad_approval_plan_id')
                ->nullable()
                ->constrained('cad_approval_plans')
                ->nullOnDelete();
            $table->string('event_type');
            $table->text('message');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cad_approval_events');
    }
};
