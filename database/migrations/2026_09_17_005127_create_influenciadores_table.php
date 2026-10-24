<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('influenciadores', function (Blueprint $table) {
            $table->id();
            $table->string('influenciador_nome');
            $table->string('influenciador_slug')->nullable()->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('influenciador_whatsapp')->nullable();
            $table->string('influenciador_instagram')->nullable();
            $table->string('influenciador_chave_pix')->nullable();
            $table->string('influenciador_status')->default('pendente');
            $table->string('influenciador_tipo_comissao')->default('percentual');
            $table->decimal('influenciador_valor_comissao', 10, 2)->default(0);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('influenciadores');
    }
};
