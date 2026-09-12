<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            // Classifica a receita desta opção de pagamento no plano de contas de
            // receita (App\Models\PlanoReceita) — é o que
            // App\Services\ImportacaoCaixaReceberService usa pra decidir em qual
            // conta cai o lançamento gerado a partir das vendas recebidas nesta
            // opção. Nula até o Gerente configurar; a importação cai num plano
            // padrão ("Outras Receitas") enquanto isso, sem bloquear a geração.
            $table->foreignId('plano_receita_id')->nullable()->after('opcaopag_desc_nfe')
                ->constrained('planos_receitas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_pagamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plano_receita_id');
        });
    }
};
