<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico de remuneração com vigência. No máximo uma vigência aberta
     * (vigente_ate nulo) por colaborador — validado no Service. Percentuais
     * padrão (50/100/20) precisam ser conferidos com a convenção coletiva.
     */
    public function up(): void
    {
        Schema::create('colaborador_remuneracoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            // App\Enums\Ponto\ModalidadeRemuneracao
            $table->string('modalidade', 30);
            $table->decimal('valor_base', 12, 2)->default(0);
            $table->decimal('valor_por_entrega', 12, 2)->default(0);
            $table->unsignedSmallInteger('carga_diaria_minutos')->nullable();
            $table->unsignedSmallInteger('carga_semanal_minutos')->nullable();
            $table->decimal('percentual_extra_util', 5, 2)->default(50);
            $table->decimal('percentual_extra_domingo_feriado', 5, 2)->default(100);
            $table->decimal('percentual_adicional_noturno', 5, 2)->default(20);
            $table->boolean('considera_hora_noturna_reduzida')->default(true);
            $table->date('vigente_desde');
            $table->date('vigente_ate')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['colaborador_id', 'vigente_desde'], 'colab_remun_vigencia_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_remuneracoes');
    }
};
