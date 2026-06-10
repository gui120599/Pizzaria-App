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
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            // Define se a forma de pagamento exige bandeira do cartão no PDV
            $table->boolean('opcaopag_requer_bandeira')->default(false)->after('opcaopag_valor_percentual_taxa');
            // Define se a forma de pagamento exige número de autorização no PDV
            $table->boolean('opcaopag_requer_autorizacao')->default(false)->after('opcaopag_requer_bandeira');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn([
                'opcaopag_requer_bandeira',
                'opcaopag_requer_autorizacao',
            ]);
        });
    }
};
