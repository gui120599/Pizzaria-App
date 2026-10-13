<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_nao_conformidades', function (Blueprint $table) {
            $table->id();
            // Uma NC por item de execução.
            $table->foreignId('checklist_execucao_item_id')
                ->unique('ck_nc_execucao_item_uq')
                ->constrained('checklist_execucao_itens', 'id', 'ck_nc_execucao_item_fk')
                ->cascadeOnDelete();
            $table->text('descricao');
            $table->text('acao_corretiva')->nullable();
            $table->foreignId('responsavel_id')
                ->nullable()
                ->constrained('users', 'id', 'ck_nc_responsavel_fk')
                ->nullOnDelete();
            $table->date('prazo')->nullable();
            // App\Enums\Checklist\NaoConformidadeStatus
            $table->string('status', 20)->default('aberta')->index();
            $table->boolean('critica')->default(false);
            $table->foreignId('resolvida_por')
                ->nullable()
                ->constrained('users', 'id', 'ck_nc_resolvida_por_fk')
                ->nullOnDelete();
            $table->dateTime('resolvida_em')->nullable();
            $table->text('observacao_resolucao')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_nao_conformidades');
    }
};
