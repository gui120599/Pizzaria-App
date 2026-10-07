<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pergunta_opcoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pergunta_opcao_pergunta_id')->constrained('perguntas')->cascadeOnDelete();
            $table->string('pergunta_opcao_nome', 80);

            // Acréscimo por unidade do item (ex.: borda recheada +R$ 10,00).
            $table->decimal('pergunta_opcao_valor', 10, 2)->default(0);

            $table->unsignedInteger('pergunta_opcao_ordem')->default(0);
            $table->boolean('pergunta_opcao_ativa')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pergunta_opcoes');
    }
};
