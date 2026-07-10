<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_receitas', function (Blueprint $table) {
            $table->id();
            // Auto-referência: grupo -> conta (pai_id null = grupo raiz)
            $table->foreignId('pai_id')
                ->nullable()
                ->constrained('planos_receitas')
                ->nullOnDelete();
            $table->string('codigo')->nullable();
            $table->string('nome');
            // Receita não usa comportamento (fixo/variável não se aplica a receita)
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['nome', 'pai_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_receitas');
    }
};
