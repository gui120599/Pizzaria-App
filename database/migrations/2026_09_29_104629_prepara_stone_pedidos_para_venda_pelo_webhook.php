<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que o StonePedido nasça SEM Venda, amarrado ao que está sendo
 * cobrado: um Pedido (balcão/retirada/delivery) ou a conta de uma SessaoMesa.
 * A Venda passa a ser criada pelo webhook charge.paid.
 *
 * Nomes de FK explícitos e curtos — o padrão inferido pelo Laravel
 * (stone_pedidos_stp_..._foreign) se aproxima do limite de 64 chars do MySQL
 * e a FK falha silenciosamente (ver feedback_mysql_identifier_length).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->foreignId('stp_sessao_mesa_id')
                ->nullable()
                ->after('stp_pedido_id')
                ->constrained('sessao_mesas', indexName: 'stp_sessao_mesa_fk')
                ->nullOnDelete();

            // Quem disparou a cobrança (garçom, entregador, caixa) — auditoria.
            $table->foreignId('stp_usuario_id')
                ->nullable()
                ->after('stp_sessao_mesa_id')
                ->constrained('users', indexName: 'stp_usuario_fk')
                ->nullOnDelete();

            // App\Enums\StonePedidoOrigem. Default 'venda' mantém a semântica
            // das linhas antigas (todas nasceram de uma Venda aberta no PDV).
            $table->string('stp_origem', 12)->default('venda')->after('stp_modo');

            // Por que a Venda não pôde ser criada no webhook (ex.: nenhuma ou
            // mais de uma sessão de caixa ABERTA). Exibido no Resource e
            // reprocessado por stone:conciliar-pedidos.
            $table->text('stp_erro')->nullable()->after('stp_origem');
        });

        DB::table('stone_pedidos')->whereNotNull('stp_venda_id')->update(['stp_origem' => 'venda']);
    }

    public function down(): void
    {
        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->dropForeign('stp_sessao_mesa_fk');
            $table->dropForeign('stp_usuario_fk');
            $table->dropColumn(['stp_sessao_mesa_id', 'stp_usuario_id', 'stp_origem', 'stp_erro']);
        });
    }
};
