<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->decimal('produto_custo_medio', 12, 4)->default(0)->after('produto_preco_custo');
            $table->decimal('produto_saldo_estoque', 12, 3)->default(0)->after('produto_custo_medio');
            $table->string('produto_unidade_estoque')->nullable()->after('produto_unidade_comercial');
            $table->boolean('produto_controla_lote')->default(false)->after('produto_controla_estoque');
            $table->boolean('produto_perecivel')->default(false)->after('produto_controla_lote');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn([
                'produto_custo_medio',
                'produto_saldo_estoque',
                'produto_unidade_estoque',
                'produto_controla_lote',
                'produto_perecivel',
            ]);
        });
    }
};
