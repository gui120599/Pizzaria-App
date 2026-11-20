<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ações pedidas pelo chat do Claude (roteiro claude-mcp-gestao.md, G3):
     * cada uma nasce como prévia (aberta) e só é executada quando o mesmo
     * usuário confirma. Fica como trilha de auditoria.
     */
    public function up(): void
    {
        Schema::create('claude_acoes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->string('tipo', 40);
            $table->json('entrada');
            $table->json('previa');
            $table->string('status', 20)->default('aberta');
            $table->dateTime('expira_em');
            $table->dateTime('confirmada_em')->nullable();
            $table->json('resultado')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['tipo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_acoes');
    }
};
