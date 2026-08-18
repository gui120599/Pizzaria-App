<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vincula opcionalmente um Prestador (fornecedor) a um cadastro de Cliente já
     * existente — usado quando o mesmo fornecedor também consome no PDV (ex.: um
     * fornecedor de serviço que faz pedidos pra descontar do próprio repasse, ver
     * LancamentosTable::acaoCompensar()).
     */
    public function up(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->foreignId('cliente_id')
                ->nullable()
                ->after('categoria')
                ->constrained('clientes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_id');
        });
    }
};
