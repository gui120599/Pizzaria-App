<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            // Comanda numerada usa a mesma tabela e o mesmo ciclo de sessão da
            // mesa — só muda a apresentação no Painel do Garçom.
            $table->enum('mesa_tipo', ['MESA', 'COMANDA'])->default('MESA')->after('mesa_nome');
            $table->unsignedInteger('mesa_numero')->nullable()->after('mesa_tipo');
            $table->unsignedTinyInteger('mesa_capacidade')->nullable()->after('mesa_numero');
            $table->string('mesa_area', 60)->nullable()->after('mesa_capacidade');
        });
    }

    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropColumn(['mesa_tipo', 'mesa_numero', 'mesa_capacidade', 'mesa_area']);
        });
    }
};
