<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pivô sem Model próprio (belongsToMany direto). Sem linha para uma
        // campanha = sem restrição, todas as formas de pagamento valem — ver
        // PromocaoAdicional::pagamentoPermitido().
        Schema::create('promocao_adicional_opcoes_pagamento', function (Blueprint $table) {
            $table->foreignId('promoad_id')
                ->constrained('promocoes_adicionais')
                ->cascadeOnDelete();
            $table->foreignId('opcaopag_id')
                ->constrained('opcoes_pagamentos')
                ->cascadeOnDelete();

            $table->primary(['promoad_id', 'opcaopag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_adicional_opcoes_pagamento');
    }
};
