<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Troco que fica na gaveta para o próximo turno e o dinheiro recolhido
     * (transferência do Caixa PDV para `recolhimento_conta_id`). O uuid liga
     * o fechamento à transferência, para estornar na reabertura.
     * `caixas.caixa_troco_padrao` é a sugestão do troco no fechamento.
     */
    public function up(): void
    {
        Schema::table('fechamentos_caixa', function (Blueprint $table) {
            $table->decimal('valor_troco_gaveta', 12, 2)->nullable();
            $table->decimal('valor_recolhido', 12, 2)->nullable();
            $table->foreignId('recolhimento_conta_id')
                ->nullable()
                ->constrained('contas_financeiras', indexName: 'fech_recolh_conta_fk')
                ->nullOnDelete();
            $table->char('recolhimento_transferencia_uuid', 36)->nullable();
        });

        Schema::table('caixas', function (Blueprint $table) {
            $table->decimal('caixa_troco_padrao', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('caixas', function (Blueprint $table) {
            $table->dropColumn('caixa_troco_padrao');
        });

        Schema::table('fechamentos_caixa', function (Blueprint $table) {
            $table->dropForeign('fech_recolh_conta_fk');
            $table->dropColumn(['valor_troco_gaveta', 'valor_recolhido', 'recolhimento_conta_id', 'recolhimento_transferencia_uuid']);
        });
    }
};
