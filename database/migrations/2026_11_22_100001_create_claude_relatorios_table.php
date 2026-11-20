<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relatórios gerados pelas rotinas do Claude (resumo do dia, sugestão de
     * compras, anomalias) e gravados pelo conector "PizzariaAPP Gestão".
     */
    public function up(): void
    {
        Schema::create('claude_relatorios', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 30)->index();
            $table->string('titulo');
            $table->longText('conteudo');
            $table->date('referencia')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'crel_user_fk')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_relatorios');
    }
};
