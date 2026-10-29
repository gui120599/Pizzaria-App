<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquininhas', function (Blueprint $table) {
            // A maquininha diz se recebe pedidos do Stone Connect — antes isso
            // era deduzido de operadora = stone + número de série.
            $table->boolean('maquininha_connect_ativo')->default(false)->after('numero_serie');
        });

        // Preserva o comportamento anterior: toda Stone com número de série
        // continua apta ao Connect. Quem não integra (ex.: Tectoy T8) é
        // desmarcado depois, pelo cadastro de Maquininhas.
        DB::table('maquininhas')
            ->where('operadora', 'stone')
            ->whereNotNull('numero_serie')
            ->where('numero_serie', '!=', '')
            ->update(['maquininha_connect_ativo' => true]);
    }

    public function down(): void
    {
        Schema::table('maquininhas', function (Blueprint $table) {
            $table->dropColumn('maquininha_connect_ativo');
        });
    }
};
