<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_itens', function (Blueprint $table) {
            $table->string('ci_cfop', 4)->nullable();
            $table->string('ci_cst_csosn', 4)->nullable();
            $table->string('ci_operacao', 40)->nullable();
            $table->decimal('ci_valor_st', 12, 4)->default(0);
            $table->decimal('ci_valor_ipi', 12, 4)->default(0);
            $table->decimal('ci_valor_fcp_st', 12, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('compra_itens', function (Blueprint $table) {
            $table->dropColumn([
                'ci_cfop',
                'ci_cst_csosn',
                'ci_operacao',
                'ci_valor_st',
                'ci_valor_ipi',
                'ci_valor_fcp_st',
            ]);
        });
    }
};
