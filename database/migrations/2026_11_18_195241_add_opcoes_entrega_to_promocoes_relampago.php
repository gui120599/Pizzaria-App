<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opções de entrega em que a promoção relâmpago vale (ids; vazio = todas),
 * igual a promoad_opcoes_entrega da promoção adicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes_relampago', function (Blueprint $table) {
            $table->json('promocao_opcoes_entrega')->nullable()->after('promocao_limite_por_pedido');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_relampago', function (Blueprint $table) {
            $table->dropColumn('promocao_opcoes_entrega');
        });
    }
};
