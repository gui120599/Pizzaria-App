<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_envios', function (Blueprint $table) {
            $table->id();
            $table->string('pe_chave', 80);
            $table->string('pe_evento', 24);
            $table->foreignId('pe_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pe_dispositivo_id')->constrained('dispositivos_push')->cascadeOnDelete();
            $table->string('pe_status', 16)->default('PENDENTE');
            $table->unsignedTinyInteger('pe_tentativas')->default(0);
            $table->unsignedSmallInteger('pe_http_status')->nullable();
            $table->string('pe_erro', 255)->nullable();
            $table->json('pe_payload');
            $table->dateTime('pe_expira_em');
            $table->timestamps();

            $table->unique(['pe_chave', 'pe_dispositivo_id'], 'push_envios_chave_dispositivo_unique');
            $table->index(['pe_status', 'pe_expira_em'], 'push_envios_status_expira_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_envios');
    }
};
