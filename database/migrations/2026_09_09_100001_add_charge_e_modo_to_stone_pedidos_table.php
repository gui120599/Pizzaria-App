<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stone_pedidos', function (Blueprint $table) {
            // O charge pago na maquininha — StoneRecebimentoService::processarChargePaid
            // já tenta gravar esses campos; sem a coluna o Eloquent ignorava em silêncio
            // (a coluna "NSU" do Resource ficava sempre vazia).
            $table->string('stp_charge_id')->nullable()->after('stp_order_code');
            $table->string('stp_charge_code')->nullable()->after('stp_charge_id');

            // 'direto' = POST /orders com payment_setup (balcão); 'listado' = sem
            // payment_setup (recebimento na entrega). Default mantém as linhas antigas
            // com a semântica atual (todas eram Listado).
            $table->string('stp_modo', 7)->default('listado')->after('stp_status');
        });
    }

    public function down(): void
    {
        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->dropColumn(['stp_charge_id', 'stp_charge_code', 'stp_modo']);
        });
    }
};
