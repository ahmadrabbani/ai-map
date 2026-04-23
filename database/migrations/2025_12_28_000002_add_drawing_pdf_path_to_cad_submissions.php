<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cad_submissions', function (Blueprint $table) {
            if (!Schema::hasColumn('cad_submissions', 'drawing_pdf_path')) {
                $table->string('drawing_pdf_path')->nullable()->after('overlay_pdf_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cad_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('cad_submissions', 'drawing_pdf_path')) {
                $table->dropColumn('drawing_pdf_path');
            }
        });
    }
};
