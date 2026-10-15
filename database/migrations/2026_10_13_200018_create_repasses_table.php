<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repasse do colaborador no período. A ligação com o contas a pagar fica
     * só em lancamentos.repasse_id (um sentido). Cancelar a aprovação deixa o
     * título Cancelado, e uma nova aprovação gera outro: o vigente é o que não
     * está cancelado.
     *
     * Itens automáticos são recriados a cada recálculo (sem soft delete).
     */
    public function up(): void
    {
        Schema::create('repasses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ponto_periodo_id')->constrained('ponto_periodos')->restrictOnDelete();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->json('remuneracao_snapshot')->nullable();
            $table->json('resumo_jornada')->nullable();
            $table->decimal('total_proventos', 12, 2)->default(0);
            $table->decimal('total_descontos', 12, 2)->default(0);
            $table->decimal('valor_liquido', 12, 2)->default(0);
            // App\Enums\Ponto\StatusRepasse
            $table->string('status', 20)->default('rascunho')->index();
            // CLT: só aprova com os valores conferidos contra o holerite da contabilidade.
            $table->boolean('conferido_holerite')->default(false);
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('aprovado_em')->nullable();
            $table->dateTime('ciente_em')->nullable();
            $table->string('ciente_ip', 45)->nullable();
            $table->dateTime('pago_em')->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['ponto_periodo_id', 'colaborador_id']);
        });

        Schema::create('repasse_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repasse_id')->constrained('repasses')->cascadeOnDelete();
            // App\Enums\Ponto\TipoItemRepasse
            $table->string('tipo', 20);
            // App\Enums\Ponto\NaturezaItem
            $table->string('natureza', 30);
            $table->string('descricao', 255);
            // Horas em decimal só para exibição (minutos/60 em 2 casas).
            $table->decimal('quantidade', 10, 2)->nullable();
            $table->decimal('valor_unitario', 12, 2)->nullable();
            // Sempre positivo; o tipo define o sinal.
            $table->decimal('valor', 12, 2);
            $table->boolean('automatico')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_itens');
        Schema::dropIfExists('repasses');
    }
};
