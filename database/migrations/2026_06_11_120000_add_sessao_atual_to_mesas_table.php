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
        Schema::table('mesas', function (Blueprint $table) {
            // Aponta para a sessão de mesa que ocupa a mesa no momento.
            // Usada para liberar a mesa apenas quando a sessão recebida na venda
            // for a mesma que está ocupando a mesa.
            $table->unsignedBigInteger('mesa_sessao_atual_id')->nullable()->after('mesa_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropColumn('mesa_sessao_atual_id');
        });
    }
};
