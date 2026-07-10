<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocao_consumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumo_promocao_id')
                ->constrained('promocoes_relampago')
                ->cascadeOnDelete();
            $table->foreignId('consumo_promocao_produto_id')
                ->constrained('promocao_relampago_produtos')
                ->cascadeOnDelete();
            $table->foreignId('consumo_item_pedido_id')
                ->constrained('itens_pedidos')
                ->cascadeOnDelete();
            $table->foreignId('consumo_pedido_id')
                ->nullable()
                ->constrained('pedidos')
                ->cascadeOnDelete();

            $table->decimal('consumo_quantidade', 10, 2);
            $table->dateTime('consumo_revertido_em')->nullable();

            $table->timestamps();

            // Um item do pedido consome no máximo uma vez. Torna consumir()
            // idempotente e impede estorno duplo em cascata (item removido de
            // pedido que depois é cancelado).
            $table->unique('consumo_item_pedido_id', 'unq_consumo_item');
            $table->index(['consumo_promocao_id', 'consumo_revertido_em'], 'idx_consumo_promocao_ativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_consumos');
    }
};
