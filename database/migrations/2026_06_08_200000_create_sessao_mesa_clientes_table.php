<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessao_mesa_clientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('smc_sessao_mesa_id');
            $table->unsignedBigInteger('smc_cliente_id');
            $table->timestamps();

            $table->foreign('smc_sessao_mesa_id')->references('id')->on('sessao_mesas')->cascadeOnDelete();
            $table->foreign('smc_cliente_id')->references('id')->on('clientes')->cascadeOnDelete();

            $table->index('smc_sessao_mesa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessao_mesa_clientes');
    }
};
