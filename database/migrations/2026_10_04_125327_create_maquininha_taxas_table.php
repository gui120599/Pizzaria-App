<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custo cobrado pela adquirente (MDR) por maquininha × bandeira × tipo.
        // Bandeira nula = vale para qualquer bandeira daquele tipo (pix sempre nula).
        Schema::create('maquininha_taxas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mt_maquininha_id')->constrained('maquininhas')->cascadeOnDelete();
            $table->foreignId('mt_cartao_id')->nullable()->constrained('cartoes_pagamentos')->nullOnDelete();
            $table->enum('mt_tipo', ['debito', 'credito', 'pix']);
            $table->decimal('mt_percentual', 5, 2);
            $table->timestamps();

            $table->unique(['mt_maquininha_id', 'mt_cartao_id', 'mt_tipo'], 'mt_maquininha_cartao_tipo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maquininha_taxas');
    }
};
