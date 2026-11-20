<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compra avulsa (Fase 5):
     * - fornecedor avulso (sem CNPJ), cadastrado pela conversa;
     * - de-para por descrição: o tipo da chave entra no unique, para que a
     *   descrição normalizada de uma compra avulsa nunca colida com o cProd
     *   de uma NF-e. O unique novo é criado antes de remover o antigo porque
     *   a FK de fp_prestador_id se apoia nele;
     * - de onde veio a informação da compra avulsa (imagem/mensagem).
     */
    public function up(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->boolean('avulso')->default(false)->after('categoria');
        });

        Schema::table('fornecedor_produtos', function (Blueprint $table) {
            $table->string('fp_tipo_chave', 10)->default('codigo')->after('fp_prestador_id');
        });

        Schema::table('fornecedor_produtos', function (Blueprint $table) {
            $table->unique(['fp_prestador_id', 'fp_tipo_chave', 'fp_codigo_fornecedor'], 'fornecedor_produto_chave_unique');
        });

        Schema::table('fornecedor_produtos', function (Blueprint $table) {
            $table->dropUnique('fornecedor_produto_codigo_unique');
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->string('compra_origem_informacao', 10)->nullable()->after('compra_origem');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn('compra_origem_informacao');
        });

        Schema::table('fornecedor_produtos', function (Blueprint $table) {
            $table->unique(['fp_prestador_id', 'fp_codigo_fornecedor'], 'fornecedor_produto_codigo_unique');
        });

        Schema::table('fornecedor_produtos', function (Blueprint $table) {
            $table->dropUnique('fornecedor_produto_chave_unique');
            $table->dropColumn('fp_tipo_chave');
        });

        Schema::table('prestadores', function (Blueprint $table) {
            $table->dropColumn('avulso');
        });
    }
};
