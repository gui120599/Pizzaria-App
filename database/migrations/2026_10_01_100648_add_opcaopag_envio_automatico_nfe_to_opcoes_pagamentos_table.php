<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->boolean('opcaopag_envio_automatico_nfe')->default(false)->after('opcaopag_desc_nfe');
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn('opcaopag_envio_automatico_nfe');
        });
    }
};
