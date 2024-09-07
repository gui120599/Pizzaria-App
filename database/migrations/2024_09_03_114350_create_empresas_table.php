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
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('empresa_razao_social');
            $table->string('empresa_nome_fantasia')->nullable();
            $table->string('empresa_cnpj')->nullable();
            $table->enum('empresa_regime_tributario',['isento', 'microempreendedorIndividual', 'simplesNacional', 'lucroPresumido', 'lucroReal', 'none'])->default('none')->nullable();
            $table->string('empresa_endereco_uf_estado')->nullable();
            $table->string('empresa_endereco_cidade_id_ibge')->nullable();
            $table->string('empresa_endereco_rua')->nullable();
            $table->string('empresa_endereco_numero_endereco')->nullable()->default('S/N')->nullable();
            $table->string('empresa_endereco_cep')->nullable();
            $table->string('empresa_endereco_complemento_endereco')->nullable();
            $table->string('empresa_endereco_bairro')->nullable();
            $table->string('empresa_endereco')->nullable();
            $table->string('empresa_api_nfeio_conta_id')->nullable();
            $table->string('empresa_api_nfeio_company_id')->nullable();
            $table->string('empresa_api_nfeio_apikey')->nullable();
            $table->enum('empresa_api_nfeio_ambiente',['test','production'])->default('test');
            $table->enum('empresa_status',['Active','Inactive'])->default('Active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
