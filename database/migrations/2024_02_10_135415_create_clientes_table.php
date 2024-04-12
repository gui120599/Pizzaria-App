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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('cliente_nome');
            $table->date('cliente_data_nascimento');
            $table->string('cliente_tipo');
            $table->string('cliente_cpf')->nullable();
            $table->string('cliente_rg')->nullable();
            $table->string('cliente_cnpj')->nullable();
            $table->string('cliente_celular')->nullable();
            $table->string('cliente_email')->nullable();
            $table->string('cliente_endereco')->nullable();
            $table->string('cliente_bairro')->nullable();
            $table->string('cliente_cidade')->nullable();
            $table->string('cliente_estado')->nullable();
            $table->string('cliente_uf_estado')->nullable();
            $table->string('cliente_cep')->nullable();
            $table->string('cliente_foto')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
