<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cartoes_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->enum('cartao_bandeira',['visa', 'mastercard', 'americanExpress', 'sorocred', 'other'])->default('other');
            $table->string('cartao_cnpj_credenciadora')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cartoes_pagamentos');
    }
};
