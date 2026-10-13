<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_modelos', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            // App\Enums\Checklist\ChecklistTipo / ChecklistSetor (string, não enum do MySQL).
            $table->string('tipo', 30)->index();
            $table->string('setor', 30)->index();
            // Dias ISO (1=seg … 7=dom). null = sob demanda, sem geração diária.
            $table->json('dias_semana')->nullable();
            $table->time('janela_inicio')->nullable();
            $table->time('janela_fim')->nullable();
            $table->unsignedSmallInteger('tolerancia_minutos')->default(30);
            $table->boolean('exige_aprovacao')->default(false);
            $table->boolean('permite_multiplas_execucoes_dia')->default(false);
            // Incrementa a cada edição de modelo que já tem execuções (snapshot nas execuções).
            $table->unsignedInteger('versao')->default(1);
            $table->boolean('ativo')->default(true)->index();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_modelos');
    }
};
