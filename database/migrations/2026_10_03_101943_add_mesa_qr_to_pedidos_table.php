<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            // Pedido feito pelo cliente no QR da mesa (pedido_origem = mesa_qr):
            // quem pediu e a chave que o celular manda para o reenvio não duplicar.
            $table->foreignId('pedido_mesa_participante_id')->nullable()->after('pedido_sessao_mesa_id')
                ->constrained('mesa_participantes')->nullOnDelete();
            $table->uuid('pedido_chave_idempotencia')->nullable()->unique()->after('pedido_mesa_participante_id');

            // Aprovação do garçom (StatusAprovacaoPedidoEnum). Null = não passou
            // por aprovação. Pendente = pedido INICIADO, fora da conta e da cozinha.
            $table->string('pedido_aprovacao_status', 20)->nullable()->after('pedido_origem');
            $table->json('pedido_aprovacao_motivos')->nullable()->after('pedido_aprovacao_status');
            $table->foreignId('pedido_aprovado_por_id')->nullable()->after('pedido_aprovacao_motivos')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pedido_aprovado_em')->nullable()->after('pedido_aprovado_por_id');
            $table->string('pedido_recusa_motivo', 255)->nullable()->after('pedido_aprovado_em');

            $table->index(['pedido_sessao_mesa_id', 'pedido_aprovacao_status'], 'idx_pedidos_sessao_aprovacao');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex('idx_pedidos_sessao_aprovacao');
            $table->dropConstrainedForeignId('pedido_aprovado_por_id');
            $table->dropConstrainedForeignId('pedido_mesa_participante_id');
            $table->dropUnique(['pedido_chave_idempotencia']);
            $table->dropColumn([
                'pedido_chave_idempotencia',
                'pedido_aprovacao_status',
                'pedido_aprovacao_motivos',
                'pedido_aprovado_em',
                'pedido_recusa_motivo',
            ]);
        });
    }
};
