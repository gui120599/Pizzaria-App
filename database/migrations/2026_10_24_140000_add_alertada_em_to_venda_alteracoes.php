<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Marca a alteração já alertada (vendas:alertar-alteracoes-abertas), para avisar uma vez só. */
    public function up(): void
    {
        Schema::table('venda_alteracoes', function (Blueprint $table) {
            $table->timestamp('alertada_em')->nullable()->after('encerrada_em');
        });
    }

    public function down(): void
    {
        Schema::table('venda_alteracoes', function (Blueprint $table) {
            $table->dropColumn('alertada_em');
        });
    }
};
