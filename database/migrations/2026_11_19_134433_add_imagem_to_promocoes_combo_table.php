<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Banner da Promoção Combo no cardápio web (card de destaque e anúncio ao abrir). */
    public function up(): void
    {
        Schema::table('promocoes_combo', function (Blueprint $table) {
            $table->string('promocombo_imagem')->nullable()->after('promocombo_descricao');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_combo', function (Blueprint $table) {
            $table->dropColumn('promocombo_imagem');
        });
    }
};
