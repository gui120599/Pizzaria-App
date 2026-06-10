<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('pedido_origem')->default('atendente')->index()->after('pedido_status');
        });

        // Backfill histórico: o checkout do cardápio que persiste pedido nasce nesta entrega,
        // então nenhum pedido anterior é realmente do cardápio. Só mesa precisa de ajuste;
        // o restante permanece no default 'atendente'.
        DB::table('pedidos')
            ->whereNotNull('pedido_sessao_mesa_id')
            ->update(['pedido_origem' => 'mesa']);
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('pedido_origem');
        });
    }
};
