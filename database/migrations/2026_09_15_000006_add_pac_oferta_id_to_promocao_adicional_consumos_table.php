<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocao_adicional_consumos', function (Blueprint $table) {
            // Aponta pra opção de produto ofertado especificamente escolhida —
            // é dela que o saldo (pao_qtd_vendida) é debitado/estornado.
            // pac_regra_id continua existindo, usado pelo limite por pedido
            // (que conta aceites por gatilho, não por opção escolhida).
            $table->foreignId('pac_oferta_id')
                ->nullable()
                ->after('pac_regra_id')
                ->constrained('promocao_adicional_ofertas')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promocao_adicional_consumos', function (Blueprint $table) {
            $table->dropForeign(['pac_oferta_id']);
            $table->dropColumn('pac_oferta_id');
        });
    }
};
