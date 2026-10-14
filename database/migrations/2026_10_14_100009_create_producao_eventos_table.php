<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Linha do tempo dos itens de produção e base dos relatórios. Append-only:
     * só created_at, sem soft delete; o model recusa update/delete.
     */
    public function up(): void
    {
        Schema::create('producao_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producao_item_id')->constrained('producao_itens')->cascadeOnDelete();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('setor_producao_id')->constrained('setores_producao')->restrictOnDelete();
            // App\Enums\Cozinha\AcaoProducao
            $table->string('acao', 30);
            $table->string('de_status', 20)->nullable();
            $table->string('para_status', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('operador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispositivo_cozinha_id')->nullable()->constrained('dispositivos_cozinha')->nullOnDelete();
            $table->string('motivo', 255)->nullable();
            $table->json('dados')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('producao_item_id', 'prod_evento_item_idx');
            $table->index(['setor_producao_id', 'created_at'], 'prod_evento_setor_data_idx');
            $table->index(['operador_id', 'created_at'], 'prod_evento_operador_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producao_eventos');
    }
};
