<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Integração com o módulo de checklists (docs/modulo-checklists.md §10):
     * tablet de destino do modelo e o operador (a pessoa, users.id) que
     * respondeu no tablet compartilhado. Tudo nullable — os checklists
     * funcionam sem estas colunas.
     */
    public function up(): void
    {
        Schema::table('checklist_modelos', function (Blueprint $table) {
            // null = todos os tablets da cozinha.
            $table->foreignId('setor_producao_id')->nullable()->constrained('setores_producao')->nullOnDelete();
        });

        Schema::table('checklist_execucoes', function (Blueprint $table) {
            $table->foreignId('operador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operador_nome', 120)->nullable();
            $table->foreignId('dispositivo_cozinha_id')->nullable()->constrained('dispositivos_cozinha')->nullOnDelete();
        });

        Schema::table('checklist_execucao_itens', function (Blueprint $table) {
            $table->foreignId('operador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operador_nome', 120)->nullable();
        });

        Schema::table('checklist_anexos', function (Blueprint $table) {
            $table->foreignId('operador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operador_nome', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_anexos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operador_id');
            $table->dropColumn('operador_nome');
        });

        Schema::table('checklist_execucao_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operador_id');
            $table->dropColumn('operador_nome');
        });

        Schema::table('checklist_execucoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispositivo_cozinha_id');
            $table->dropConstrainedForeignId('operador_id');
            $table->dropColumn('operador_nome');
        });

        Schema::table('checklist_modelos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('setor_producao_id');
        });
    }
};
