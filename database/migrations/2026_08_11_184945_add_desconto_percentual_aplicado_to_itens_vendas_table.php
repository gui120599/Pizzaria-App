<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->decimal('item_venda_desconto_percentual_aplicado', 10, 2)->default(0)->after('item_venda_desconto');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->dropColumn('item_venda_desconto_percentual_aplicado');
        });
    }
};
