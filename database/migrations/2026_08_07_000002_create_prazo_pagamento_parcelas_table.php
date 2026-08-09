<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prazo_pagamento_parcelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prazo_pagamento_id')
                ->constrained('prazos_pagamento')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('parcela_numero');
            // Dias corridos a partir da data-base (ex.: vencimento do boleto/entrada da compra).
            $table->unsignedSmallInteger('parcela_dias');
            // Percentual do valor total atribuído a esta parcela. A soma das parcelas de um
            // mesmo prazo não precisa fechar em 100% (parcelamento com juros embutido soma mais).
            $table->decimal('parcela_percentual', 7, 4);
            $table->timestamps();

            // Nome explícito: o gerado automaticamente estoura o limite de 64 chars do MySQL.
            $table->unique(['prazo_pagamento_id', 'parcela_numero'], 'prazo_pagamento_parcelas_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prazo_pagamento_parcelas');
    }
};
