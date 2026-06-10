<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            // Valor efetivamente aplicado à venda (espelha pg_venda_valor_pagamento)
            $table->decimal('pg_venda_valor_recebido', 10, 2)->default(0)->after('pg_venda_valor_pagamento');
            // Valor que o cliente entregou (ex.: nota de R$100 para pagar R$70)
            $table->decimal('pg_venda_valor_pago_pelo_cliente', 10, 2)->default(0)->after('pg_venda_valor_recebido');
            // Troco deste pagamento (= pago pelo cliente − recebido)
            $table->decimal('pg_venda_valor_troco', 10, 2)->default(0)->after('pg_venda_valor_pago_pelo_cliente');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            $table->dropColumn([
                'pg_venda_valor_recebido',
                'pg_venda_valor_pago_pelo_cliente',
                'pg_venda_valor_troco',
            ]);
        });
    }
};
