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
        Schema::create('prestadores', function (Blueprint $table) {
            $table->id();

            // Controlado por Enum PHP
            $table->string('tipo');
            $table->string('categoria')->nullable(); // Ex: Funcionário, Terceirizado, Autônomo

            $table->string('razao_social')->nullable();
            $table->string('nome_fantasia')->nullable();

            $table->string('nome')->nullable(); // para PF

            $table->string('cpf_cnpj')->nullable();
            $table->string('inscricao_estadual')->nullable();

            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('celular')->nullable();

            $table->string('cep', 10)->nullable();
            $table->string('endereco')->nullable();
            $table->string('complemento')->nullable();
            $table->string('numero')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cidade')->nullable();
            $table->string('uf', 2)->nullable();

            $table->text('observacoes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prestadores');
    }
};
