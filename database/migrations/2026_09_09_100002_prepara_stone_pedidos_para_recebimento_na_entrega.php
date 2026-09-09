<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prepara o hub stone_pedidos para o cenário futuro de recebimento na entrega
 * (pedido do entregador, sem Venda aberta no PDV):
 *  - stp_venda_id passa a ser nullable;
 *  - novo stp_pedido_id (FK pedidos) para amarrar o StonePedido a um Pedido.
 *
 * Nenhuma UI de entregador entra agora — só o schema e os ramos defensivos no
 * StoneRecebimentoService. O botão "Lançar pedido total" do PDV continua sempre
 * amarrado a uma Venda.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL não deixa alterar uma coluna referenciada por FK — derruba a FK antes.
        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->dropForeign('stp_venda_fk');
        });

        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->foreignId('stp_venda_id')->nullable()->change();
        });

        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->foreign('stp_venda_id', 'stp_venda_fk')
                ->references('id')->on('vendas')
                ->cascadeOnDelete();

            $table->foreignId('stp_pedido_id')
                ->nullable()
                ->after('stp_venda_id')
                ->constrained('pedidos', indexName: 'stp_pedido_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->dropForeign('stp_pedido_fk');
            $table->dropColumn('stp_pedido_id');
            $table->dropForeign('stp_venda_fk');
        });

        // Remove as linhas órfãs antes de voltar a coluna para NOT NULL.
        DB::table('stone_pedidos')->whereNull('stp_venda_id')->delete();

        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->foreignId('stp_venda_id')->nullable(false)->change();
        });

        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->foreign('stp_venda_id', 'stp_venda_fk')
                ->references('id')->on('vendas')
                ->cascadeOnDelete();
        });
    }
};
