<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fornecedor_condicoes_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fcc_prestador_id')->constrained('prestadores')->cascadeOnDelete();
            $table->foreignId('fcc_prazo_pagamento_id')->nullable()->constrained('prazos_pagamento')->nullOnDelete();
            $table->string('fcc_prazo_base', 20)->default('entrada');
            $table->string('fcc_forma_pagamento', 30)->nullable();
            $table->foreignId('fcc_conta_financeira_id')->nullable()->constrained('contas_financeiras')->nullOnDelete();
            $table->string('fcc_data_entrada_regra', 20)->default('hoje');
            $table->timestamps();

            $table->unique('fcc_prestador_id', 'fcc_prestador_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fornecedor_condicoes_compra');
    }
};
