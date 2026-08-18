<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro opcional de bandeira/autorização quando um pagamento de título é
     * recebido via cartão (ex.: recebimento de venda fiado na aba Pendentes do
     * PDV) — só pra conciliação interna com a operadora, sem efeito fiscal (a
     * NFC-e da venda, se emitida, já foi declarada com tPag=90/withoutPayment
     * no momento da finalização — ver NfeIoService::montarPagamentos()).
     */
    public function up(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->foreignId('cartao_id')
                ->nullable()
                ->after('forma_pagamento')
                ->constrained('cartoes_pagamentos')
                ->nullOnDelete();
            $table->string('numero_autorizacao_cartao')->nullable()->after('cartao_id');
        });
    }

    public function down(): void
    {
        Schema::table('lancamento_pagamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cartao_id');
            $table->dropColumn('numero_autorizacao_cartao');
        });
    }
};
