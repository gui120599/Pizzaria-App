<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carteira do influenciador (Fase 2): a comissão vira saldo (extrato
     * append-only), o pagamento de comissão vira resgate e entra o consumo em
     * pizza. As tabelas antigas de pagamento nunca tiveram dado em produção.
     */
    public function up(): void
    {
        Schema::table('comissoes', function (Blueprint $table) {
            $table->dropForeign(['comissao_pgcom_id']);
            $table->dropColumn(['comissao_pgcom_id', 'comissao_paga_em']);
            $table->renameColumn('comissao_aprovada_em', 'comissao_creditada_em');
        });

        DB::table('comissoes')->whereIn('comissao_status', ['aprovada', 'paga'])->update(['comissao_status' => 'creditada']);

        Schema::dropIfExists('pagamentos_comissao');

        Schema::table('influenciadores', function (Blueprint $table) {
            // A chave Pix passa a ser guardada criptografada (cast encrypted): não cabe em varchar(255).
            $table->text('influenciador_chave_pix')->nullable()->change();
            $table->text('influenciador_cpf')->nullable();
            $table->char('influenciador_cpf_hash', 64)->nullable()->unique();
            $table->foreignId('influenciador_prestador_id')->nullable()
                ->constrained('prestadores', indexName: 'influ_prestador_fk')->nullOnDelete();
        });

        Schema::create('resgates_carteira', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rc_influenciador_id')->constrained('influenciadores', indexName: 'rc_influ_fk')->restrictOnDelete();
            $table->decimal('rc_valor', 10, 2);
            $table->foreignId('rc_lancamento_id')->nullable()->constrained('lancamentos', indexName: 'rc_lancamento_fk')->restrictOnDelete();
            // Disco local (privado): comprovante é dado financeiro/pessoal.
            $table->string('rc_comprovante_path')->nullable();
            $table->foreignId('rc_user_id')->nullable()->constrained('users', indexName: 'rc_user_fk')->nullOnDelete();
            $table->timestamp('rc_pago_em');
            $table->timestamps();
        });

        // Extrato append-only: sem updated_at, sem soft delete. Correção é movimento inverso.
        Schema::create('carteira_movimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cm_influenciador_id')->constrained('influenciadores', indexName: 'cm_influ_fk')->restrictOnDelete();
            $table->string('cm_tipo');
            $table->decimal('cm_valor', 10, 2);
            $table->foreignId('cm_comissao_id')->nullable()->constrained('comissoes', indexName: 'cm_comissao_fk')->restrictOnDelete();
            $table->foreignId('cm_resgate_id')->nullable()->constrained('resgates_carteira', indexName: 'cm_resgate_fk')->restrictOnDelete();
            $table->foreignId('cm_pagamento_venda_id')->nullable()->constrained('pagamentos_vendas', indexName: 'cm_pgvenda_fk')->nullOnDelete();
            $table->foreignId('cm_user_id')->nullable()->constrained('users', indexName: 'cm_user_fk')->nullOnDelete();
            $table->string('cm_observacao')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['cm_influenciador_id', 'created_at'], 'cm_influ_data_idx');
        });

        Schema::create('codigos_resgate', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cr_influenciador_id')->constrained('influenciadores', indexName: 'cr_influ_fk')->cascadeOnDelete();
            $table->char('cr_codigo_hash', 64);
            $table->timestamp('cr_expira_em');
            $table->timestamp('cr_usado_em')->nullable();
            $table->foreignId('cr_pagamento_venda_id')->nullable()->constrained('pagamentos_vendas', indexName: 'cr_pgvenda_fk')->nullOnDelete();
            $table->timestamps();

            $table->index(['cr_codigo_hash', 'cr_usado_em'], 'cr_hash_usado_idx');
        });

        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->boolean('opcaopag_carteira_influenciador')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropColumn('opcaopag_carteira_influenciador');
        });

        Schema::dropIfExists('codigos_resgate');
        Schema::dropIfExists('carteira_movimentos');
        Schema::dropIfExists('resgates_carteira');

        Schema::table('influenciadores', function (Blueprint $table) {
            $table->dropForeign('influ_prestador_fk');
            $table->dropColumn(['influenciador_cpf', 'influenciador_cpf_hash', 'influenciador_prestador_id']);
        });
    }
};
