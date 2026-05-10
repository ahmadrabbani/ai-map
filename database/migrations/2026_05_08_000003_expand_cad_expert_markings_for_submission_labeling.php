<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_expert_markings', function (Blueprint $table) {
            if (! Schema::hasColumn('cad_expert_markings', 'cad_submission_id')) {
                $table->foreignId('cad_submission_id')->nullable()->after('id')->constrained('cad_submissions')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('cad_expert_markings', 'label_key')) {
                $table->string('label_key')->nullable()->index()->after('cad_submission_id');
            }
            if (! Schema::hasColumn('cad_expert_markings', 'label_name')) {
                $table->string('label_name')->nullable()->after('label_key');
            }
            if (! Schema::hasColumn('cad_expert_markings', 'geometry_type')) {
                $table->string('geometry_type')->nullable()->after('label_name');
            }
            if (! Schema::hasColumn('cad_expert_markings', 'points_json')) {
                $table->json('points_json')->nullable()->after('geometry_type');
            }
            if (! Schema::hasColumn('cad_expert_markings', 'measurement_json')) {
                $table->json('measurement_json')->nullable()->after('points_json');
            }
            if (! Schema::hasColumn('cad_expert_markings', 'status')) {
                $table->string('status')->default('draft')->after('measurement_json');
            }
            if (! Schema::hasColumn('cad_expert_markings', 'source')) {
                $table->string('source')->default('expert_drawn')->after('status');
            }
            if (! Schema::hasColumn('cad_expert_markings', 'updated_by')) {
                $table->string('updated_by')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cad_expert_markings', function (Blueprint $table) {
            $drop = [];
            foreach (['cad_submission_id','label_key','label_name','geometry_type','points_json','measurement_json','status','source','updated_by'] as $col) {
                if (Schema::hasColumn('cad_expert_markings', $col)) {
                    $drop[] = $col;
                }
            }
            if (in_array('cad_submission_id', $drop, true)) {
                $table->dropConstrainedForeignId('cad_submission_id');
                $drop = array_values(array_filter($drop, fn ($c) => $c !== 'cad_submission_id'));
            }
            if (! empty($drop)) {
                $table->dropColumn($drop);
            }
        });
    }
};
