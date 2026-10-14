<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tablet pareado a um ou mais setores. Entra na conta técnica da estação
     * (role EstacaoCozinha) por um QR de uso único.
     */
    public function up(): void
    {
        Schema::create('dispositivos_cozinha', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 60);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // ids de setores_producao
            $table->json('setores');
            // App\Enums\Cozinha\ModoExibicaoCozinha / TamanhoFonteCozinha
            $table->string('modo_exibicao', 20)->default('itens');
            $table->string('tamanho_fonte', 10)->default('normal');
            $table->boolean('som_ativo')->default(true);
            // sha256 do token de pareamento (uso único); null depois de usado.
            $table->char('token_hash', 64)->nullable()->unique();
            $table->dateTime('token_expira_em')->nullable();
            $table->dateTime('pareado_em')->nullable();
            $table->dateTime('ultimo_ping_em')->nullable();
            $table->string('ultimo_ip', 45)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_cozinha');
    }
};
