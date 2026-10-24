<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuração do programa de influenciadores no singleton Empresa: tetos do
     * cupom criado pelo influenciador, comissão padrão aplicada na aprovação e
     * plano/conta do financeiro (usados pelo resgate em Pix, Fase 2).
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->decimal('empresa_influ_teto_percentual', 5, 2)->default(10);
            $table->decimal('empresa_influ_teto_valor_fixo', 10, 2)->default(10);
            $table->unsignedInteger('empresa_influ_teto_usos_total')->nullable();
            $table->string('empresa_influ_comissao_tipo')->default('percentual');
            $table->decimal('empresa_influ_comissao_valor', 10, 2)->default(5);
            $table->foreignId('empresa_influ_plano_despesa_id')->nullable()
                ->constrained('planos_despesas', indexName: 'emp_influ_plano_fk')->nullOnDelete();
            $table->foreignId('empresa_influ_conta_pix_id')->nullable()
                ->constrained('contas_financeiras', indexName: 'emp_influ_conta_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropForeign('emp_influ_plano_fk');
            $table->dropForeign('emp_influ_conta_fk');
            $table->dropColumn([
                'empresa_influ_teto_percentual',
                'empresa_influ_teto_valor_fixo',
                'empresa_influ_teto_usos_total',
                'empresa_influ_comissao_tipo',
                'empresa_influ_comissao_valor',
                'empresa_influ_plano_despesa_id',
                'empresa_influ_conta_pix_id',
            ]);
        });
    }
};
