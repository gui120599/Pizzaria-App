<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Só colunas novas, nullable ou com default — nada existente é alterado. */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            // Cache de App\Enums\Cozinha\StatusProducaoPedido para listagens.
            $table->string('pedido_producao_status', 20)->nullable()->index('pedidos_producao_status_idx');
            $table->string('pedido_prioridade_producao', 10)->nullable();
            $table->dateTime('pedido_producao_iniciada_em')->nullable();
            $table->dateTime('pedido_producao_concluida_em')->nullable();
        });

        Schema::table('itens_pedidos', function (Blueprint $table) {
            // Cache do status de produção do item para o card do Painel de Pedidos.
            $table->string('item_pedido_producao_status', 20)->nullable();
        });

        Schema::table('produtos', function (Blueprint $table) {
            // Refrigerante lata, água: não gera item de produção nem segura o pedido.
            $table->boolean('produto_dispensa_producao')->default(false);
        });

        Schema::table('adicionais', function (Blueprint $table) {
            $table->decimal('adicional_quantidade_porcao', 10, 3)->nullable();
            $table->string('adicional_unidade_porcao', 10)->nullable();
            $table->string('adicional_instrucao_preparo', 255)->nullable();
            $table->foreignId('adicional_insumo_id')->nullable()->constrained('produtos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('adicionais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adicional_insumo_id');
            $table->dropColumn(['adicional_quantidade_porcao', 'adicional_unidade_porcao', 'adicional_instrucao_preparo']);
        });

        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('produto_dispensa_producao');
        });

        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropColumn('item_pedido_producao_status');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropIndex('pedidos_producao_status_idx');
            $table->dropColumn([
                'pedido_producao_status',
                'pedido_prioridade_producao',
                'pedido_producao_iniciada_em',
                'pedido_producao_concluida_em',
            ]);
        });
    }
};
