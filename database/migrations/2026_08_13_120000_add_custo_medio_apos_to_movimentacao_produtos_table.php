<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            $table->decimal('mov_custo_medio_apos', 16, 8)->nullable()->after('mov_saldo_apos');
        });
    }

    public function down(): void
    {
        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            $table->dropColumn('mov_custo_medio_apos');
        });
    }
};
