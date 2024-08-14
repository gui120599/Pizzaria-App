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
        Schema::create('opcoes_pagamentos', function (Blueprint $table) {
            $table->id();
            $table->string('opcaopag_nome');
            $table->enum('opcaopag_tipo_taxa',['N/A','DESCONTAR','ACRESCENTAR'])->default('N/A')->nullable();
            $table->enum('opcaopag_desc_nfe',['cash','cheque','creditCard','debitCard','storeCredict','foodVouchers','mealVouchers','giftVouchers','fuelVouchers','bankBill','withoutPayment','others'])->nullable('others');
            $table->decimal('opcaopag_valor_percentual_taxa',10,2)->default('0.00')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opcoes_pagamentos');
    }
};
