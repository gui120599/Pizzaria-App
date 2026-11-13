<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Produto cadastrado pela automação de compras (agente com autonomia
     * "completa"): fica marcado para revisão e fora das fichas técnicas até
     * alguém aprovar.
     */
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->boolean('produto_cadastro_pendente_revisao')->default(false);
            // manual | agente (null = cadastro anterior à automação).
            $table->string('produto_origem_cadastro', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn(['produto_cadastro_pendente_revisao', 'produto_origem_cadastro']);
        });
    }
};
