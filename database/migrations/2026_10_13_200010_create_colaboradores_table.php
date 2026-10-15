<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colaborador da folha de ponto. Login (users) é opcional: quem não tem
     * bate ponto no quiosque pelo PIN. "Ativo" é derivado de
     * data_desligamento; soft delete só para cadastro feito por engano.
     * O favorecido no contas a pagar é o Prestador espelho
     * (prestadores.colaborador_id), mantido pelo observer do colaborador.
     */
    public function up(): void
    {
        Schema::create('colaboradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('nome', 120);
            $table->text('cpf')->nullable();  // cast encrypted
            // HMAC-SHA256 dos dígitos (busca e unicidade sem decriptar).
            $table->char('cpf_hash', 64)->nullable()->unique();
            $table->string('cargo', 80)->nullable();
            $table->string('setor', 80)->nullable();
            // App\Enums\Ponto\VinculoColaborador
            $table->string('vinculo', 30);
            // App\Enums\Ponto\PeriodicidadeRepasse
            $table->string('periodicidade_repasse', 30);
            $table->date('data_admissao');
            $table->date('data_desligamento')->nullable()->index();
            // App\Enums\TipoChavePix
            $table->string('tipo_chave_pix', 20)->nullable();
            $table->text('chave_pix')->nullable();  // cast encrypted
            $table->string('pin_quiosque')->nullable();  // cast hashed
            // Conta entregas a partir de pedidos.pedido_usuario_entrega_id (exige user_id).
            $table->boolean('entregador')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colaboradores');
    }
};
