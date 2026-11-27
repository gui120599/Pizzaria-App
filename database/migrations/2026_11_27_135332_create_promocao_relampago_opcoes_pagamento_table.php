<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formas de pagamento aceitas pela promoção relâmpago (ex.: só Pix ou
     * Dinheiro). Sem linhas = todas as formas valem. Com outra forma, o item
     * sai pelo preço normal.
     */
    public function up(): void
    {
        Schema::create('promocao_relampago_opcoes_pagamento', function (Blueprint $table) {
            $table->unsignedBigInteger('promocao_id');
            $table->unsignedBigInteger('opcaopag_id');

            $table->primary(['promocao_id', 'opcaopag_id'], 'prop_pk');
            $table->foreign('promocao_id', 'prop_promocao_fk')->references('id')->on('promocoes_relampago')->cascadeOnDelete();
            $table->foreign('opcaopag_id', 'prop_opcaopag_fk')->references('id')->on('opcoes_pagamentos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_relampago_opcoes_pagamento');
    }
};
