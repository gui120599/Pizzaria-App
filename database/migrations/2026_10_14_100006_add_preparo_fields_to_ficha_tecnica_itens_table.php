<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos de exibição na cozinha nas linhas da ficha de custo/estoque.
     * Só colunas novas, todas com default ou nullable.
     */
    public function up(): void
    {
        Schema::table('ficha_tecnica_itens', function (Blueprint $table) {
            // App\Enums\Cozinha\EscopoIngrediente: base aparece uma vez no meio a meio; cobertura/finalização × fração.
            $table->string('fti_escopo', 20)->default('cobertura')->after('fti_percentual_perda');
            // Chave que casa com o "SEM …" da observação do item (ex.: "cebola").
            $table->string('fti_opcional_ref', 60)->nullable()->after('fti_escopo');
            $table->string('fti_foto_porcao')->nullable()->after('fti_opcional_ref');
            $table->string('fti_observacao')->nullable()->after('fti_foto_porcao');
            $table->unsignedSmallInteger('fti_ordem')->default(0)->after('fti_observacao');
        });
    }

    public function down(): void
    {
        Schema::table('ficha_tecnica_itens', function (Blueprint $table) {
            $table->dropColumn(['fti_escopo', 'fti_opcional_ref', 'fti_foto_porcao', 'fti_observacao', 'fti_ordem']);
        });
    }
};
