<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_rule_results', function (Blueprint $table) {
            if (!Schema::hasColumn('cad_rule_results', 'source')) {
                $table->string('source')->default('system')->after('cad_submission_id');
            }
        });

        DB::table('cad_rule_results')->whereNull('source')->update(['source' => 'system']);
    }

    public function down(): void
    {
        Schema::table('cad_rule_results', function (Blueprint $table) {
            if (Schema::hasColumn('cad_rule_results', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
