<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocao_adicional_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pac_regra_id')
                ->constrained('promocao_adicional_regras')
                ->cascadeOnDelete();
            $table->foreignId('pac_item_pedido_gatilho_id')
                ->constrained('itens_pedidos')
                ->cascadeOnDelete();
            $table->foreignId('pac_item_pedido_oferta_id')
                ->constrained('itens_pedidos')
                ->cascadeOnDelete();
            $table->foreignId('pac_pedido_id')
                ->nullable()
                ->constrained('pedidos')
                ->cascadeOnDelete();

            // Congela o valor cobrado, para auditoria mesmo se a regra mudar depois.
            $table->decimal('pac_valor_adicional_cobrado', 10, 2);
            // Unidades da oferta debitadas do teto agregado da regra (normalmente
            // 1 por aceite, mas o campo existe para não travar em quantidade fixa).
            $table->decimal('pac_quantidade', 10, 2)->default(1);
            $table->dateTime('pac_revertido_em')->nullable();

            $table->timestamps();

            // O item da oferta consome no máximo uma vez — torna consumir()
            // idempotente e impede estorno duplo em cascata.
            $table->unique('pac_item_pedido_oferta_id', 'unq_pac_item_oferta');
            $table->index(['pac_regra_id', 'pac_revertido_em'], 'idx_pac_regra_ativo');
            $table->index(['pac_pedido_id', 'pac_revertido_em'], 'idx_pac_pedido_ativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_adicional_consumos');
    }
};
