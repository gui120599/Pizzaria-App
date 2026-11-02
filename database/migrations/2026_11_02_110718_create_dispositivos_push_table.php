<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos_push', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dp_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('dp_endpoint');
            $table->char('dp_endpoint_hash', 64)->unique('dispositivos_push_endpoint_hash_unique');
            $table->text('dp_chave_p256dh');
            $table->text('dp_chave_auth');
            $table->string('dp_content_encoding', 16)->default('aes128gcm');
            $table->string('dp_plataforma', 16)->default('outro');
            $table->string('dp_navegador', 32)->nullable();
            $table->boolean('dp_instalado')->default(false);
            $table->string('dp_user_agent', 255)->nullable();
            $table->boolean('dp_ativo')->default(true)->index();
            $table->dateTime('dp_pausado_ate')->nullable();
            $table->dateTime('dp_ultimo_sucesso_em')->nullable();
            $table->dateTime('dp_ultima_falha_em')->nullable();
            $table->unsignedSmallInteger('dp_falhas_consecutivas')->default(0);
            $table->dateTime('dp_desativado_em')->nullable();
            $table->string('dp_motivo_desativacao', 32)->nullable();
            $table->timestamps();

            $table->index(['dp_user_id', 'dp_ativo'], 'dispositivos_push_user_ativo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_push');
    }
};
