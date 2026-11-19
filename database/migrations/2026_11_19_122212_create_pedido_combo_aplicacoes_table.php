<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico das promoções combo aplicadas em cada pedido, com o retrato
     * da promoção (nome, preço, quantidade) e as unidades de cada linha.
     */
    public function up(): void
    {
        Schema::create('pedido_combo_aplicacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pca_pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('pca_promocao_combo_id')->nullable()->constrained('promocoes_combo')->nullOnDelete();
            $table->string('pca_chave', 191);
            $table->string('pca_nome');
            $table->unsignedTinyInteger('pca_quantidade');
            $table->decimal('pca_preco', 10, 2);
            $table->decimal('pca_valor_cheio', 10, 2);
            $table->decimal('pca_desconto', 10, 2);
            $table->timestamps();

            $table->unique(['pca_pedido_id', 'pca_chave'], 'unq_pca_pedido_chave');
        });

        Schema::create('pedido_combo_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pci_aplicacao_id')->constrained('pedido_combo_aplicacoes')->cascadeOnDelete();
            // Cascade: remover a linha do pedido (LancamentoItemPedidoService::remover) não pode esbarrar na FK.
            $table->foreignId('pci_item_pedido_id')->constrained('itens_pedidos')->cascadeOnDelete();
            $table->decimal('pci_unidades', 8, 2);
            $table->decimal('pci_valor_cheio', 10, 2);
            $table->decimal('pci_desconto', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_combo_itens');
        Schema::dropIfExists('pedido_combo_aplicacoes');
    }
};
