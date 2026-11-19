<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arte da Promoção Combo para celular (1080 × 1080, quadrada). A
     * promocombo_imagem fica sendo a de computador (1680 × 640, 21:8).
     */
    public function up(): void
    {
        Schema::table('promocoes_combo', function (Blueprint $table) {
            $table->string('promocombo_imagem_mobile')->nullable()->after('promocombo_imagem');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_combo', function (Blueprint $table) {
            $table->dropColumn('promocombo_imagem_mobile');
        });
    }
};
