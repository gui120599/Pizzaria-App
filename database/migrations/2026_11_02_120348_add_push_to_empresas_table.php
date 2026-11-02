<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('empresa_push_destino_mesa', 16)->default('GARCOM_DA_MESA');
            $table->boolean('empresa_push_repetir_ate_atender')->default(true);
            $table->unsignedTinyInteger('empresa_push_repetir_intervalo_min')->default(2);
            $table->unsignedTinyInteger('empresa_push_repetir_max')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn([
                'empresa_push_destino_mesa',
                'empresa_push_repetir_ate_atender',
                'empresa_push_repetir_intervalo_min',
                'empresa_push_repetir_max',
            ]);
        });
    }
};
