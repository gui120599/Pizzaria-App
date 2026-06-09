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
            $table->boolean('opcaopag_aparece_cardapio')->default(true)->after('opcaopag_nome');
            $table->text('opcaopag_descricao')->nullable()->after('opcaopag_aparece_cardapio');
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn(['opcaopag_aparece_cardapio', 'opcaopag_descricao']);
        });
    }
};
