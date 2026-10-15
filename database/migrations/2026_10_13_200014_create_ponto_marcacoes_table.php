<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marcações de ponto — append-only ("REP-P ready"): sem updated_at nem
     * deleted_at, o model recusa update/delete e correção é só por ajuste.
     *
     * NSR e hash encadeado (cadeia global) são gerados sob lockForUpdate na
     * linha 'marcacoes' de ponto_sequencias, criada aqui.
     */
    public function up(): void
    {
        Schema::create('ponto_sequencias', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 30)->unique();
            $table->unsignedBigInteger('ultimo_nsr')->default(0);
            $table->char('ultimo_hash', 64)->nullable();
            $table->timestamps();
        });

        DB::table('ponto_sequencias')->insert([
            'nome' => 'marcacoes',
            'ultimo_nsr' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('ponto_marcacoes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('nsr')->unique();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            // Sempre now() do servidor; o horário do aparelho é ignorado.
            $table->dateTime('registrada_em')->index();
            // Dia operacional (config operacao.virada_dia).
            $table->date('data_referencia')->index();
            // App\Enums\Ponto\TipoMarcacao (só rótulo; a apuração usa a ordem cronológica)
            $table->string('tipo_sugerido', 30)->nullable();
            // App\Enums\Ponto\OrigemMarcacao
            $table->string('origem', 30);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('precisao_m')->nullable();
            $table->boolean('dentro_da_cerca')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('foto_caminho')->nullable();
            $table->char('hash_anterior', 64)->nullable();
            $table->char('hash', 64);
            // restrict (não nullOnDelete): a ação da FK seria um UPDATE na marcação.
            $table->foreignId('registrada_por')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['colaborador_id', 'data_referencia'], 'ponto_marc_colab_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ponto_marcacoes');
        Schema::dropIfExists('ponto_sequencias');
    }
};
