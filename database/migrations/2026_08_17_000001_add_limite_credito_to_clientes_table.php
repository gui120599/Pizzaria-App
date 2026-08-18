<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Limite de crédito para vendas fiado no PDV. Nulo = sem crédito liberado
     * (trava por padrão até alguém cadastrar um limite explícito pro cliente).
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->decimal('cliente_limite_credito', 10, 2)->nullable()->after('cliente_email');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('cliente_limite_credito');
        });
    }
};
