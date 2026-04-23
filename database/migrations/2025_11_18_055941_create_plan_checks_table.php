<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
    {
        Schema::create('plan_checks', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename')->nullable();
            $table->float('required_setback_ft')->default(5);
            $table->float('global_min_setback_ft')->nullable();
            $table->float('left_setback_ft')->nullable();
            $table->float('right_setback_ft')->nullable();
            $table->boolean('meets_requirement')->default(false);
            $table->json('raw_result')->nullable();
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_checks');
    }
};
