<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compra_itens', function (Blueprint $table) {
            $table->foreignId('ci_marca_id')->nullable()->after('ci_descricao_fornecedor')
                ->constrained('marcas')->nullOnDelete();
        });

        Schema::table('estoque_lotes', function (Blueprint $table) {
            $table->foreignId('lote_marca_id')->nullable()->after('lote_codigo')
                ->constrained('marcas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compra_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ci_marca_id');
        });

        Schema::table('estoque_lotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_marca_id');
        });
    }
};
