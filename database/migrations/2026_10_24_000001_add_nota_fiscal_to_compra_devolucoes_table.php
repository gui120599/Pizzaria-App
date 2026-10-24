<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo da devolução com a NF-e do fornecedor que a documenta: a Compra
     * importada do XML (compra_nota_id) e a chave de acesso, única para a mesma
     * nota não gerar duas devoluções.
     */
    public function up(): void
    {
        Schema::table('compra_devolucoes', function (Blueprint $table) {
            $table->unsignedBigInteger('compra_nota_id')->nullable();
            $table->string('chave_nfe', 44)->nullable();

            $table->unique('chave_nfe', 'compra_dev_chave_uq');
            $table->foreign('compra_nota_id', 'compra_dev_nota_fk')
                ->references('id')->on('compras')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compra_devolucoes', function (Blueprint $table) {
            $table->dropForeign('compra_dev_nota_fk');
            $table->dropUnique('compra_dev_chave_uq');
            $table->dropColumn(['compra_nota_id', 'chave_nfe']);
        });
    }
};
