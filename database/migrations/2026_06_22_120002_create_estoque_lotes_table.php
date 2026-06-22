<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lote_produto_id')->constrained('produtos')->cascadeOnDelete();
            $table->string('lote_codigo')->nullable();
            $table->date('lote_validade')->nullable();
            $table->decimal('lote_qtd_inicial', 12, 3)->default(0);
            $table->decimal('lote_qtd_atual', 12, 3)->default(0);
            $table->decimal('lote_custo_unitario', 12, 4)->default(0);
            $table->dateTime('lote_data_entrada')->nullable();
            $table->string('lote_status')->default('ativo'); // ativo | esgotado | vencido
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lote_produto_id', 'lote_validade']); // FEFO
            $table->index(['lote_produto_id', 'lote_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_lotes');
    }
};
