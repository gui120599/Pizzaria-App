<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pix direto na conta da empresa (chave CNPJ), conferido pelo extrato e
     * não por maquininha — com a tarifa que o banco cobra. As formas Pix que
     * já têm "CNPJ" no nome nascem marcadas.
     */
    public function up(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->boolean('opcaopag_pix_cnpj')->default(false)->after('opcaopag_stone_integrada');
            $table->decimal('opcaopag_tarifa_percentual', 5, 2)->nullable()->after('opcaopag_pix_cnpj');
        });

        DB::table('opcoes_pagamentos')
            ->where('opcaopag_desc_nfe', 'InstantPayment')
            ->where('opcaopag_nome', 'like', '%CNPJ%')
            ->update(['opcaopag_pix_cnpj' => true]);
    }

    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn(['opcaopag_pix_cnpj', 'opcaopag_tarifa_percentual']);
        });
    }
};
