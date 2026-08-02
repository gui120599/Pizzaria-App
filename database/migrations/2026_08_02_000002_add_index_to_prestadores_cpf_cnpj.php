<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->index('cpf_cnpj', 'prestadores_cpf_cnpj_index');
        });
    }

    public function down(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->dropIndex('prestadores_cpf_cnpj_index');
        });
    }
};
