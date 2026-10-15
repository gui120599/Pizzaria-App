<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Período de apuração. Períodos da mesma periodicidade não se sobrepõem
     * (validado no Service; o unique cobre só o início repetido).
     */
    public function up(): void
    {
        Schema::create('ponto_periodos', function (Blueprint $table) {
            $table->id();
            // App\Enums\Ponto\PeriodicidadeRepasse
            $table->string('periodicidade', 30);
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->date('data_pagamento')->nullable();
            // App\Enums\Ponto\StatusPeriodo
            $table->string('status', 30)->default('aberto')->index();
            $table->foreignId('fechado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('fechado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['periodicidade', 'data_inicio']);
            $table->index(['data_inicio', 'data_fim']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ponto_periodos');
    }
};
