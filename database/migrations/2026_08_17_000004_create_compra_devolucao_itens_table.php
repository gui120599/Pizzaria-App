<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compra_devolucao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_devolucao_id')
                ->constrained('compra_devolucoes')
                ->cascadeOnDelete();
            $table->foreignId('compra_item_id')
                ->constrained('compra_itens')
                ->restrictOnDelete();
            $table->decimal('quantidade', 12, 4);
            $table->decimal('valor_unitario', 12, 8);
            $table->decimal('valor_total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_devolucao_itens');
    }
};
