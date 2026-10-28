<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prazo_pagamento_parcelas', function (Blueprint $table) {
            $table->string('parcela_forma_pagamento', 30)->nullable()->after('parcela_percentual');
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->string('compra_vencimento_origem', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropColumn('compra_vencimento_origem');
        });

        Schema::table('prazo_pagamento_parcelas', function (Blueprint $table) {
            $table->dropColumn('parcela_forma_pagamento');
        });
    }
};
