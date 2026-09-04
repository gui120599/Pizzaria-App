<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            // Marca a forma de pagamento como integrada à maquininha Stone (Connect):
            // o PDV envia a cobrança automaticamente e lança o pagamento pelo webhook.
            // Exige opcaopag_desc_nfe ∈ {creditCard, debitCard} (validado no form).
            $table->boolean('opcaopag_stone_integrada')->default(false)->after('opcaopag_requer_autorizacao');
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn('opcaopag_stone_integrada');
        });
    }
};
