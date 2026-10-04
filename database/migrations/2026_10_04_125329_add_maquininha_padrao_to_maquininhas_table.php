<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquininhas', function (Blueprint $table) {
            // Usada quando o pagamento não informa a maquininha (tela legada,
            // pagamentos antigos) para achar a taxa da adquirente.
            $table->boolean('maquininha_padrao')->default(false)->after('identificador_externo');
        });
    }

    public function down(): void
    {
        Schema::table('maquininhas', function (Blueprint $table) {
            $table->dropColumn('maquininha_padrao');
        });
    }
};
