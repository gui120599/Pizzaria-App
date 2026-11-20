<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ordem dos adicionais em dois níveis:
 *  - adicional_ordem: ordem geral, preenchida aqui pela ordem de cadastro;
 *  - ap_ordem (no vínculo): ordem própria do produto. Começa vazia em todos
 *    os vínculos, então nada muda até alguém ordenar um produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adicionais', function (Blueprint $table) {
            $table->unsignedInteger('adicional_ordem')->default(0)->after('adicional_valor');
        });

        Schema::table('adicionais_produtos', function (Blueprint $table) {
            $table->unsignedInteger('ap_ordem')->nullable()->after('ap_produto_id');
        });

        $ordem = 0;

        foreach (DB::table('adicionais')->orderBy('id')->pluck('id') as $id) {
            DB::table('adicionais')->where('id', $id)->update(['adicional_ordem' => ++$ordem]);
        }
    }

    public function down(): void
    {
        Schema::table('adicionais_produtos', function (Blueprint $table) {
            $table->dropColumn('ap_ordem');
        });

        Schema::table('adicionais', function (Blueprint $table) {
            $table->dropColumn('adicional_ordem');
        });
    }
};
