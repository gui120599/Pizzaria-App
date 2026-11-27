<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artes da promoção relâmpago, como as do combo: computador (1680 × 640,
     * 21:8) e celular (1080 × 1080). Card do cardápio e aviso ao abrir.
     */
    public function up(): void
    {
        Schema::table('promocoes_relampago', function (Blueprint $table) {
            $table->string('promocao_imagem')->nullable()->after('promocao_descricao');
            $table->string('promocao_imagem_mobile')->nullable()->after('promocao_imagem');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_relampago', function (Blueprint $table) {
            $table->dropColumn(['promocao_imagem', 'promocao_imagem_mobile']);
        });
    }
};
