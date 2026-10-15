<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiantamento/vale/consumo descontado no repasse. Vale e "outro" tiram
     * dinheiro de uma conta ("Sai de", obrigatório no Service); consumo
     * interno e saldo devedor de período anterior não movimentam dinheiro e
     * ficam sem conta — por isso conta_financeira_id é nullable no banco.
     * A ligação com o contas a pagar fica em lancamentos.adiantamento_id.
     */
    public function up(): void
    {
        Schema::create('adiantamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colaborador_id')->constrained('colaboradores')->restrictOnDelete();
            $table->date('data');
            // App\Enums\Ponto\NaturezaAdiantamento
            $table->string('natureza', 20);
            $table->decimal('valor', 12, 2);
            $table->string('descricao', 255)->nullable();
            $table->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras')->restrictOnDelete();
            // Sessão de caixa tocada quando a conta é caixa_pdv.
            $table->foreignId('sessao_caixa_id')->nullable()->constrained('sessao_caixas')->nullOnDelete();
            // Repasse em que foi descontado.
            $table->foreignId('repasse_id')->nullable()->constrained('repasses')->nullOnDelete();
            // App\Enums\Ponto\StatusAdiantamento
            $table->string('status', 20)->default('aberto');
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['colaborador_id', 'status']);
        });

        Schema::table('repasse_itens', function (Blueprint $table) {
            // Item de desconto automático gerado a partir de um adiantamento.
            $table->foreignId('adiantamento_id')->nullable()->after('automatico')->constrained('adiantamentos')->nullOnDelete();
        });

        Schema::table('movimentacoes_financeiras', function (Blueprint $table) {
            $table->foreign('adiantamento_id')->references('id')->on('adiantamentos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_financeiras', function (Blueprint $table) {
            $table->dropForeign(['adiantamento_id']);
        });

        Schema::table('repasse_itens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adiantamento_id');
        });

        Schema::dropIfExists('adiantamentos');
    }
};
