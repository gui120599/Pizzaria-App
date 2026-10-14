<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item de produção: uma linha por (item do pedido × etapa × unidade ×
     * geração). Guarda o snapshot do que deve ser feito no momento em que
     * entrou na fila.
     */
    public function up(): void
    {
        Schema::create('producao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('item_pedido_id')->constrained('itens_pedidos')->cascadeOnDelete();
            $table->foreignId('setor_producao_id')->constrained('setores_producao')->restrictOnDelete();
            $table->unsignedTinyInteger('etapa')->default(1);
            $table->unsignedTinyInteger('etapa_total')->default(1);
            $table->unsignedSmallInteger('unidade_seq')->default(1);
            $table->unsignedTinyInteger('geracao')->default(1);
            // decimal: há itens legados com 0,5 (meia pizza lançada em linha própria).
            $table->decimal('quantidade', 8, 3)->default(1);
            // App\Enums\Cozinha\StatusItemProducao / PrioridadeProducao
            $table->string('status', 20);
            $table->string('prioridade', 10)->default('normal');
            $table->json('snapshot');
            // Origem do pedido (App\Enums\PedidoOrigemEnum) e rótulo pronto para o card.
            $table->string('origem', 20)->nullable();
            $table->string('referencia', 80)->nullable();
            $table->foreignId('ficha_tecnica_id')->nullable()->constrained('fichas_tecnicas')->nullOnDelete();
            $table->unsignedInteger('ficha_versao')->nullable();
            $table->unsignedSmallInteger('tempo_previsto_min')->nullable();
            $table->dateTime('liberar_em')->nullable();
            $table->dateTime('liberado_em')->nullable();
            $table->dateTime('iniciado_em')->nullable();
            $table->dateTime('concluido_em')->nullable();
            $table->dateTime('expedido_em')->nullable();
            $table->dateTime('cancelado_em')->nullable();
            // App\Enums\Cozinha\MotivoCancelamentoProducao
            $table->string('motivo_cancelamento', 30)->nullable();
            $table->dateTime('cancelamento_ciente_em')->nullable();
            $table->dateTime('alterado_apos_inicio_em')->nullable();
            $table->dateTime('alteracao_ciente_em')->nullable();
            // A pessoa que produziu (operador do tablet); iniciado_por/concluido_por = conta logada.
            $table->foreignId('operador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operador_nome', 120)->nullable();
            $table->foreignId('iniciado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('concluido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('refeito_de_id')->nullable()->constrained('producao_itens')->nullOnDelete();
            // App\Enums\Cozinha\MotivoRefacao
            $table->string('motivo_refacao', 30)->nullable();
            // Incrementada a cada transição: o tablet envia a que viu (otimista).
            $table->unsignedInteger('versao')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // Idempotência do roteador.
            $table->unique(['item_pedido_id', 'etapa', 'unidade_seq', 'geracao'], 'prod_item_unidade_uq');
            $table->index(['setor_producao_id', 'status', 'liberado_em'], 'prod_item_setor_status_idx');
            $table->index(['pedido_id', 'etapa'], 'prod_item_pedido_etapa_idx');
            $table->index(['status', 'liberar_em'], 'prod_item_status_liberar_idx');
            $table->index(['operador_id', 'concluido_em'], 'prod_item_operador_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producao_itens');
    }
};
