<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conta onde cai o dinheiro: a da adquirente (maquininha) e a das formas
     * sem maquininha, como PIX CNPJ e vales. Dinheiro vai sempre para o Caixa
     * PDV, então a conta da opção "cash" é ignorada.
     */
    public function up(): void
    {
        Schema::table('maquininhas', function (Blueprint $table) {
            $table->foreignId('conta_financeira_id')
                ->nullable()
                ->constrained('contas_financeiras', indexName: 'maquininhas_conta_fin_fk')
                ->nullOnDelete();
        });

        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->foreignId('conta_financeira_id')
                ->nullable()
                ->constrained('contas_financeiras', indexName: 'opcoes_pag_conta_fin_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropForeign('opcoes_pag_conta_fin_fk');
            $table->dropColumn('conta_financeira_id');
        });

        Schema::table('maquininhas', function (Blueprint $table) {
            $table->dropForeign('maquininhas_conta_fin_fk');
            $table->dropColumn('conta_financeira_id');
        });
    }
};
