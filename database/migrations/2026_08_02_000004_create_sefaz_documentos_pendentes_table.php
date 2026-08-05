<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sefaz_documentos_pendentes', function (Blueprint $table) {
            $table->id();
            $table->string('sdp_chave_acesso', 44)->unique();
            $table->string('sdp_cnpj_emitente', 14)->nullable();
            $table->timestamp('sdp_manifestado_em')->nullable();
            $table->unsignedInteger('sdp_tentativas')->default(0);
            $table->timestamp('sdp_ultima_tentativa_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sefaz_documentos_pendentes');
    }
};
