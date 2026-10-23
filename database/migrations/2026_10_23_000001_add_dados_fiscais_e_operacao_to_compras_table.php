<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->unsignedTinyInteger('compra_tp_nf')->nullable();
            $table->unsignedTinyInteger('compra_fin_nfe')->nullable();
            $table->string('compra_nat_op')->nullable();
            $table->string('compra_dest_documento', 14)->nullable();
            $table->decimal('compra_valor_nf', 12, 2)->nullable();
            $table->decimal('compra_valor_st', 12, 2)->default(0);
            $table->decimal('compra_valor_ipi', 12, 2)->default(0);
            $table->decimal('compra_valor_seguro', 12, 2)->default(0);
            $table->decimal('compra_valor_fcp_st', 12, 2)->default(0);
            $table->json('compra_notas_referenciadas')->nullable();
            $table->json('compra_pagamentos_nf')->nullable();
            $table->json('compra_duplicatas_nf')->nullable();
            $table->string('compra_operacao', 40)->nullable();
            $table->string('compra_operacao_confianca', 20)->nullable();
            $table->json('compra_operacao_criterios')->nullable();
            $table->unsignedBigInteger('compra_operacao_definida_por')->nullable();
            $table->dateTime('compra_operacao_definida_em')->nullable();
            $table->text('compra_operacao_motivo')->nullable();

            $table->index('compra_operacao', 'compras_operacao_idx');
            $table->foreign('compra_operacao_definida_por', 'compras_op_def_por_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropForeign('compras_op_def_por_fk');
            $table->dropIndex('compras_operacao_idx');
            $table->dropColumn([
                'compra_tp_nf',
                'compra_fin_nfe',
                'compra_nat_op',
                'compra_dest_documento',
                'compra_valor_nf',
                'compra_valor_st',
                'compra_valor_ipi',
                'compra_valor_seguro',
                'compra_valor_fcp_st',
                'compra_notas_referenciadas',
                'compra_pagamentos_nf',
                'compra_duplicatas_nf',
                'compra_operacao',
                'compra_operacao_confianca',
                'compra_operacao_criterios',
                'compra_operacao_definida_por',
                'compra_operacao_definida_em',
                'compra_operacao_motivo',
            ]);
        });
    }
};
