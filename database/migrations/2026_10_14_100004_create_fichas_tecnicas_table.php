<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabeçalho de preparo da ficha técnica: uma por produto (o tamanho já é
     * o produto — "Calabresa" de Pizza Grande). Os ingredientes continuam em
     * `ficha_tecnica_itens`, a mesma fonte do CMV e da baixa de estoque; o
     * rendimento continua em `produtos.produto_ficha_rendimento`.
     */
    public function up(): void
    {
        Schema::create('fichas_tecnicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produto_id')->unique()->constrained('produtos')->cascadeOnDelete();
            $table->text('descricao')->nullable();
            $table->unsignedSmallInteger('tempo_preparo_min')->nullable();
            $table->string('foto_apresentacao')->nullable();
            $table->string('video_url')->nullable();
            $table->json('alergenos')->nullable();
            $table->text('pontos_criticos')->nullable();
            // Contador de revisões (auditoria): não guarda cópia dos ingredientes antigos.
            $table->unsignedInteger('versao')->default(1);
            $table->boolean('ativo')->default(true);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas_tecnicas');
    }
};
