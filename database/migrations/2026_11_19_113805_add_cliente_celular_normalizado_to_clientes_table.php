<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Chave de comparação do celular (App\Support\Telefone::normalizar). O valor
 * original em cliente_celular não é alterado aqui: os cadastros antigos (com
 * máscara, +55, sem o nono dígito) continuam como estão e passam a ser
 * encontrados pela chave (App\Console\Commands\NormalizarTelefonesClientes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('cliente_celular_normalizado', 20)->nullable()->after('cliente_celular');
            $table->index('cliente_celular_normalizado');
        });

        // Preenche a chave dos cadastros já existentes (não altera cliente_celular).
        Artisan::call('clientes:normalizar-telefones');
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['cliente_celular_normalizado']);
            $table->dropColumn('cliente_celular_normalizado');
        });
    }
};
