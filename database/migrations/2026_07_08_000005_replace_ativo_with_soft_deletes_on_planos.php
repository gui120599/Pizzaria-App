<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O plano de contas passa a usar SoftDeletes do Laravel para "desativar" contas,
     * no lugar do booleano `ativo` (mesmo padrão adotado para centros de custo).
     */
    public function up(): void
    {
        Schema::table('planos_despesas', function (Blueprint $table) {
            $table->dropColumn('ativo');
            $table->softDeletes();
        });

        Schema::table('planos_receitas', function (Blueprint $table) {
            $table->dropColumn('ativo');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('planos_despesas', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->boolean('ativo')->default(true);
        });

        Schema::table('planos_receitas', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->boolean('ativo')->default(true);
        });
    }
};
