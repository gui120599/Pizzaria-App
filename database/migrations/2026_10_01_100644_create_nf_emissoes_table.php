<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nf_emissoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venda_id')->constrained('vendas');
            $table->foreignId('numeracao_id')->constrained('nf_numeracoes');
            $table->unsignedSmallInteger('modelo');
            $table->unsignedSmallInteger('serie');
            $table->unsignedInteger('numero');
            $table->string('status', 40)->default('Reservado');
            $table->string('nfeio_id')->nullable();
            $table->string('decisao_envio', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviada_em')->nullable();
            $table->timestamp('autorizada_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['numeracao_id', 'numero'], 'nf_emissoes_numero_unique');
            $table->index('nfeio_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nf_emissoes');
    }
};
