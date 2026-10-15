<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prestador espelho do colaborador (favorecido dos repasses no contas a
     * pagar). Sincronizado num sentido só pelo observer do Colaborador; no
     * financeiro fica somente leitura. PIX serve também para fornecedores.
     */
    public function up(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->foreignId('colaborador_id')->nullable()->unique()->after('cliente_id')->constrained('colaboradores')->restrictOnDelete();
            // App\Enums\TipoChavePix
            $table->string('tipo_chave_pix', 20)->nullable()->after('celular');
            $table->text('chave_pix')->nullable()->after('tipo_chave_pix');  // cast encrypted
        });
    }

    public function down(): void
    {
        Schema::table('prestadores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('colaborador_id');
            $table->dropColumn(['tipo_chave_pix', 'chave_pix']);
        });
    }
};
