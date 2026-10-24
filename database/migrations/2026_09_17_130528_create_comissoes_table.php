<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comissoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comissao_influenciador_id')->constrained('influenciadores')->cascadeOnDelete();
            $table->foreignId('comissao_pedido_id')->unique()->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('comissao_cupom_id')->nullable()->constrained('cupons')->nullOnDelete();
            $table->decimal('comissao_base_calculo', 10, 2);
            $table->string('comissao_tipo');
            $table->decimal('comissao_valor_referencia', 10, 2);
            $table->decimal('comissao_valor', 10, 2);
            $table->string('comissao_status')->default('pendente');
            $table->timestamp('comissao_aprovada_em')->nullable();
            $table->timestamp('comissao_paga_em')->nullable();
            $table->text('comissao_observacao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('comissao_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comissoes');
    }
};
