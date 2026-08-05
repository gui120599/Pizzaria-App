<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('empresa_certificado_path')->nullable();
            $table->text('empresa_certificado_senha')->nullable();
            $table->string('empresa_certificado_titular_cnpj', 14)->nullable();
            $table->string('empresa_certificado_titular_nome')->nullable();
            $table->date('empresa_certificado_validade')->nullable();
            $table->timestamp('empresa_certificado_atualizado_em')->nullable();
            $table->enum('empresa_sefaz_ambiente', ['homologacao', 'producao'])->default('homologacao');
            $table->unsignedBigInteger('empresa_sefaz_ultimo_nsu')->default(0);
            $table->timestamp('empresa_sefaz_ultima_consulta_em')->nullable();
            $table->boolean('empresa_sefaz_auto_importacao_ativa')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn([
                'empresa_certificado_path',
                'empresa_certificado_senha',
                'empresa_certificado_titular_cnpj',
                'empresa_certificado_titular_nome',
                'empresa_certificado_validade',
                'empresa_certificado_atualizado_em',
                'empresa_sefaz_ambiente',
                'empresa_sefaz_ultimo_nsu',
                'empresa_sefaz_ultima_consulta_em',
                'empresa_sefaz_auto_importacao_ativa',
            ]);
        });
    }
};
