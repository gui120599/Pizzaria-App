<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estoque_correcoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ec_produto_id')->constrained('produtos');
            $table->foreignId('ec_movimentacao_id')->constrained('movimentacao_produtos');
            $table->foreignId('ec_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('ec_motivo')->nullable();
            $table->json('ec_dados_antes');
            $table->json('ec_dados_depois');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque_correcoes');
    }
};
