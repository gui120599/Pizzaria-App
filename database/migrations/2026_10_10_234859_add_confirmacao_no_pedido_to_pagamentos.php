<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagamento combinado no pedido que o atendente já conferiu (ex.: PIX CNPJ
 * visto no extrato na hora do pedido) e o vínculo do pagamento da venda com
 * a forma combinada que o originou — o Nova Venda lança as formas do pedido
 * sozinho e as desfaz se o pedido sair da venda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->boolean('opcaopag_confirmavel_no_pedido')->default(false)->after('opcaopag_pix_cnpj');
        });

        DB::table('opcoes_pagamentos')->where('opcaopag_pix_cnpj', true)->update(['opcaopag_confirmavel_no_pedido' => true]);

        Schema::table('pagamentos_pedidos', function (Blueprint $table) {
            $table->boolean('pg_pedido_pago')->default(false)->after('pg_pedido_valor_troco_para');
            $table->dateTime('pg_pedido_pago_em')->nullable()->after('pg_pedido_pago');
            $table->foreignId('pg_pedido_pago_usuario_id')->nullable()->after('pg_pedido_pago_em')
                ->constrained('users', indexName: 'pagamentos_pedidos_pago_usuario_fk')->nullOnDelete();
        });

        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            $table->foreignId('pg_venda_pagamento_pedido_id')->nullable()->after('pg_venda_venda_id')
                ->constrained('pagamentos_pedidos', indexName: 'pagamentos_vendas_pagamento_pedido_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos_vendas', function (Blueprint $table) {
            $table->dropForeign('pagamentos_vendas_pagamento_pedido_fk');
            $table->dropColumn('pg_venda_pagamento_pedido_id');
        });

        Schema::table('pagamentos_pedidos', function (Blueprint $table) {
            $table->dropForeign('pagamentos_pedidos_pago_usuario_fk');
            $table->dropColumn(['pg_pedido_pago', 'pg_pedido_pago_em', 'pg_pedido_pago_usuario_id']);
        });

        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn('opcaopag_confirmavel_no_pedido');
        });
    }
};
