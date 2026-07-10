<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropColumn('favorecido');

            // Favorecido em lançamentos a pagar: fornecedor cadastrado em Prestadores (categoria = fornecedor)
            $table->foreignId('favorecido_id')
                ->nullable()
                ->after('descricao')
                ->constrained('prestadores')
                ->restrictOnDelete();

            // Favorecido em lançamentos a receber: cliente cadastrado
            $table->foreignId('cliente_id')
                ->nullable()
                ->after('favorecido_id')
                ->constrained('clientes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('favorecido_id');
            $table->dropConstrainedForeignId('cliente_id');
            $table->string('favorecido')->nullable()->after('descricao');
        });
    }
};
