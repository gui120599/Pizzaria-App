<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            // Quantidade que a ficha técnica produz (ex.: 1 pizza, ou 5000 ml de
            // molho por batelada). O custo unitário = custo total da ficha / rendimento.
            $table->decimal('produto_ficha_rendimento', 12, 3)->default(1)->after('produto_perecivel');
        });
    }

    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('produto_ficha_rendimento');
        });
    }
};
