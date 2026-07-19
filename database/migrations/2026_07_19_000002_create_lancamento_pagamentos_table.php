<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Um lançamento (a pagar ou a receber) pode ser quitado em mais de um pagamento
     * (parcelas, pagamento parcial + complemento depois etc). O status/valor pago do
     * lançamento passam a ser derivados da soma destas linhas (ver Lancamento::recalcularStatus).
     */
    public function up(): void
    {
        Schema::create('lancamento_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lancamento_id')
                ->constrained('lancamentos')
                ->cascadeOnDelete();
            $table->decimal('valor', 12, 2);
            $table->date('data_pagamento')->index();
            $table->string('forma_pagamento')->nullable();  // usa App\Enums\FormaPagamento
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index(['lancamento_id', 'data_pagamento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lancamento_pagamentos');
    }
};
