<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Troco em dinheiro (gaveta) ou Pix (conta PIX CNPJ) — ver
     * App\Enums\FormaTrocoEnum. O padrão é por forma de pagamento; o pagamento
     * guarda o que o operador escolheu (nulo = sem troco ou legado = dinheiro).
     */
    public function up(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->string('opcaopag_troco_padrao')->default('dinheiro')->after('opcaopag_pix_cnpj');
        });

        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            $table->string('pg_venda_forma_troco')->nullable()->after('pg_venda_valor_troco');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            $table->dropColumn('pg_venda_forma_troco');
        });

        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn('opcaopag_troco_padrao');
        });
    }
};
