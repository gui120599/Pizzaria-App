<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erro_envio: motivo da última falha de envio à NFe.io (antes só o status
     * FalhaEnvio ficava gravado). comprador_motivo_omissao: por que a nota
     * saiu sem identificar o cliente (cadastro incompleto para a NFC-e).
     */
    public function up(): void
    {
        Schema::table('nf_emissoes', function (Blueprint $table) {
            $table->text('erro_envio')->nullable()->after('autorizada_em');
            $table->string('comprador_motivo_omissao')->nullable()->after('erro_envio');
        });
    }

    public function down(): void
    {
        Schema::table('nf_emissoes', function (Blueprint $table) {
            $table->dropColumn(['erro_envio', 'comprador_motivo_omissao']);
        });
    }
};
