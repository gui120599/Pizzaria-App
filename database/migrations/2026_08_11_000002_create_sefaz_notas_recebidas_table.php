<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sefaz_notas_recebidas', function (Blueprint $table) {
            $table->id();
            $table->string('snr_chave_acesso', 44)->unique();
            $table->unsignedBigInteger('snr_nsu');
            $table->string('snr_cnpj_emitente', 14)->nullable();
            $table->string('snr_nome_emitente')->nullable();
            $table->decimal('snr_valor', 12, 2)->nullable();
            $table->date('snr_data_emissao')->nullable();
            $table->string('snr_status')->default('pendente');
            $table->foreignId('snr_compra_id')->nullable()->constrained('compras')->nullOnDelete();
            $table->timestamp('snr_encontrada_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sefaz_notas_recebidas');
    }
};
