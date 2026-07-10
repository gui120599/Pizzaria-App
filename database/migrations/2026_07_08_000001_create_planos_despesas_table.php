<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_despesas', function (Blueprint $table) {
            $table->id();
            // Auto-referência: grupo -> conta (pai_id null = grupo raiz)
            $table->foreignId('pai_id')
                ->nullable()
                ->constrained('planos_despesas')
                ->nullOnDelete();
            $table->string('codigo')->nullable();  // código contábil, ex.: "3.1.01"
            $table->string('nome');
            // Aqui mora o "custo fixo/variável" (usa App\Enums\Comportamento)
            $table->string('comportamento')->index();
            // Aqui mora o "mensal/eventual" (usa App\Enums\Periodicidade)
            $table->string('periodicidade')->default('eventual')->index();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->unique(['nome', 'pai_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_despesas');
    }
};
