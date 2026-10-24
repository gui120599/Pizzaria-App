<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alteração completa de venda finalizada (App\Services\AlteracaoVendaService):
     * a venda é reaberta, editada no PDV e finalizada de novo. `antes` guarda o
     * retrato da venda (itens, pagamentos, vínculos de pedido, fiado) para
     * descartar a alteração e restaurar o original; `depois` o resumo ao concluir.
     */
    public function up(): void
    {
        Schema::create('venda_alteracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venda_id')->constrained('vendas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo');
            $table->string('status', 20)->default('aberta')->index();
            $table->json('antes');
            $table->json('depois')->nullable();
            $table->timestamp('encerrada_em')->nullable();
            $table->timestamps();

            $table->index(['venda_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venda_alteracoes');
    }
};
