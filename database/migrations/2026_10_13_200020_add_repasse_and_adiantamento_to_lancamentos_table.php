<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Origem do título no módulo de ponto, no padrão das FKs dedicadas
     * (compra_id, contrato_id, venda_id, sessao_caixa_id). Não é unique:
     * cancelar a aprovação do repasse deixa o título Cancelado e uma nova
     * aprovação gera outro.
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('repasse_id')->nullable()->after('sessao_caixa_id')->constrained('repasses')->restrictOnDelete();
            $table->foreignId('adiantamento_id')->nullable()->after('repasse_id')->constrained('adiantamentos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adiantamento_id');
            $table->dropConstrainedForeignId('repasse_id');
        });
    }
};
