<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('layer_aliases')) {
            return;
        }

        Schema::create('layer_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias_name');
            $table->string('alias_name_normalized')->index();
            $table->string('official_layer_name');
            $table->string('official_layer_name_normalized')->index();
            $table->string('semantic_label', 120)->nullable()->index();
            $table->unsignedInteger('hit_count')->default(1);
            $table->unsignedInteger('confidence_score')->default(80);
            $table->boolean('is_active')->default(true);
            $table->string('source', 60)->default('expert_mapping');
            $table->timestamps();

            $table->unique(['alias_name_normalized', 'official_layer_name_normalized'], 'layer_alias_unique_norm_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layer_aliases');
    }
};
