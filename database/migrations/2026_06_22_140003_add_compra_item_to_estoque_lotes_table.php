<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estoque_lotes', function (Blueprint $table) {
            $table->foreignId('lote_compra_item_id')->nullable()->after('lote_produto_id')
                ->constrained('compra_itens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('estoque_lotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_compra_item_id');
        });
    }
};
