<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier 4 (analytics): motivo e autoria do cancelamento.
 *
 * Hoje 6k+ pedidos cancelados não registram por quê nem por quem. Estas
 * colunas (motivo categorizado via MotivoCancelamentoEnum + usuário que
 * cancelou) destravam a análise de perdas no dashboard operacional.
 *
 * Históricos ficam nulos (não havia captura) — o dado passa a existir das
 * próximas cancelas em diante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('pedido_motivo_cancelamento', 40)->nullable()->after('pedido_status');
            $table->unsignedBigInteger('pedido_usuario_cancelou_id')->nullable()->after('pedido_motivo_cancelamento');
            $table->index('pedido_usuario_cancelou_id', 'pedidos_usuario_cancelou_idx');
        });

        Schema::table('vendas', function (Blueprint $table) {
            $table->string('venda_motivo_cancelamento', 40)->nullable()->after('venda_status');
            $table->unsignedBigInteger('venda_usuario_cancelou_id')->nullable()->after('venda_motivo_cancelamento');
            $table->index('venda_usuario_cancelou_id', 'vendas_usuario_cancelou_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex('pedidos_usuario_cancelou_idx');
            $table->dropColumn(['pedido_motivo_cancelamento', 'pedido_usuario_cancelou_id']);
        });

        Schema::table('vendas', function (Blueprint $table) {
            $table->dropIndex('vendas_usuario_cancelou_idx');
            $table->dropColumn(['venda_motivo_cancelamento', 'venda_usuario_cancelou_id']);
        });
    }
};
