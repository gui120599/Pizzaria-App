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
        Schema::create('sessao_mesas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sessao_mesa_mesa_id');
            $table->unsignedBigInteger('sessao_mesa_cliente_id')->nullable();
            $table->unsignedBigInteger('sessao_mesa_usuario_id');
            $table->enum('sessao_mesa_status',['ABERTA','FECHADA','CANCELADA'])->default('ABERTA');
            $table->text('sessao_mesa_motivo_cancelamento')->nullable();
            $table->timestamps();

            $table->foreign('sessao_mesa_mesa_id')->references('id')->on('mesas');
            $table->foreign('sessao_mesa_cliente_id')->references('id')->on('clientes');
            $table->foreign('sessao_mesa_usuario_id')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessao_mesas');
    }
};
