<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feriado recorrente (fixo) vale todo ano pelo dia/mês de `data`; os
     * móveis (Carnaval, Paixão, Corpus Christi) são gravados ano a ano.
     */
    public function up(): void
    {
        Schema::create('feriados', function (Blueprint $table) {
            $table->id();
            $table->date('data')->index();
            $table->string('nome', 80);
            // App\Enums\Ponto\AbrangenciaFeriado
            $table->string('abrangencia', 20)->default('nacional');
            $table->boolean('recorrente_anual')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['data', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feriados');
    }
};
