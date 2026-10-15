<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalas', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80);
            // App\Enums\Ponto\TipoEscala
            $table->string('tipo', 30);
            // CLT art. 58 §1º: até 5 min por marcação e 10 min no dia não contam.
            $table->unsignedTinyInteger('tolerancia_marcacao_min')->default(5);
            $table->unsignedTinyInteger('tolerancia_diaria_min')->default(10);
            // Intervalo mínimo exigido no dia (abaixo dele = inconsistência).
            $table->unsignedSmallInteger('intervalo_minimo_min')->default(60);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('escala_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escala_id')->constrained('escalas')->cascadeOnDelete();
            // ISO-8601: 1 = segunda … 7 = domingo (mesma convenção dos checklists).
            $table->unsignedTinyInteger('dia_semana');
            $table->boolean('folga')->default(false);
            // saida < entrada = vira a meia-noite (turno 18:00–01:00).
            $table->time('entrada')->nullable();
            $table->time('saida')->nullable();
            $table->unsignedSmallInteger('intervalo_minutos')->default(0);
            $table->timestamps();

            $table->unique(['escala_id', 'dia_semana']);
        });

        Schema::create('colaborador_escalas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->foreignId('escala_id')->constrained('escalas')->restrictOnDelete();
            $table->date('vigente_desde');
            $table->date('vigente_ate')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['colaborador_id', 'vigente_desde'], 'colab_escala_vigencia_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaborador_escalas');
        Schema::dropIfExists('escala_dias');
        Schema::dropIfExists('escalas');
    }
};
