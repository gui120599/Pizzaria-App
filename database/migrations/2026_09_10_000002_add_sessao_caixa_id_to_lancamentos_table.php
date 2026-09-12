<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            // Sessão de caixa que originou este título a receber, quando importado
            // automaticamente na confirmação do fechamento (ver
            // App\Services\ImportacaoCaixaReceberService). Sem unique: a granularidade
            // é 1 lançamento por opção de pagamento, então uma sessão pode ter N
            // lançamentos com o mesmo sessao_caixa_id — a idempotência da importação é
            // garantida no Service (mesmo padrão de CompraService::gerarContaPagar,
            // que usa ->lancamentos()->exists() em vez de unique).
            $table->foreignId('sessao_caixa_id')->nullable()->after('venda_id')
                ->constrained('sessao_caixas')->nullOnDelete();
            $table->index('sessao_caixa_id');
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sessao_caixa_id');
        });
    }
};
