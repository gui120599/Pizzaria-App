<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Um celular identificado numa sessão de mesa (pedido pelo QR). O token
        // fica num cookie do celular; aqui só o hash. Vale enquanto a sessão
        // estiver ABERTA — fechar a mesa mata o acesso.
        Schema::create('mesa_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mp_sessao_mesa_id')->constrained('sessao_mesas')->cascadeOnDelete();
            $table->foreignId('mp_cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('mp_nome', 120);
            $table->string('mp_celular', 20);
            $table->char('mp_token_hash', 64)->unique();
            $table->string('mp_ip', 45)->nullable();
            $table->string('mp_user_agent', 255)->nullable();
            $table->timestamp('mp_ultimo_acesso_em')->nullable();
            $table->timestamp('mp_bloqueado_em')->nullable();
            $table->foreignId('mp_bloqueado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesa_participantes');
    }
};
