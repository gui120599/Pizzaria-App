<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas_moedas', function (Blueprint $table) {
            $table->id();
            $table->string('descricao');
            $table->decimal('valor', 10, 2)->unique();
            $table->string('tipo');  // usa App\Enums\TipoNotaMoeda
            // Maior valor primeiro na tela de contagem.
            $table->unsignedSmallInteger('ordem_exibicao')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas_moedas');
    }
};
