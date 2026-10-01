<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cada transição de status de um pedido — de onde veio, pra onde
 * foi, quem fez e quando. Não existia nenhum rastro disso antes: os 7 campos
 * `pedido_datahora_*` guardam só a última entrada em cada status, sem usuário,
 * sem ordem entre regressões e sem histórico de quem cancelou/confirmou.
 *
 * Alimentada por HistoricoStatusPedidoObserver (created + updated), que cobre
 * todo caminho que passa por save()/update() no model. Os três caminhos que
 * gravam por query builder (e por isso pulam os eventos do Eloquent) chamam
 * HistoricoStatusPedido::registrar() explicitamente — ver EntregaService,
 * ConfirmacoesPedidos e VendaObserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historico_status_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hsp_pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->string('hsp_status_de', 20)->nullable();
            $table->string('hsp_status_para', 20);
            $table->foreignId('hsp_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['hsp_pedido_id', 'created_at'], 'hist_status_pedidos_pedido_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_status_pedidos');
    }
};
