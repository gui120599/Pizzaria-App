<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('pedido_cupom_id')->nullable()->after('pedido_valor_desconto')
                ->constrained('cupons')->nullOnDelete();
            $table->decimal('pedido_valor_desconto_cupom', 10, 2)->default(0)
                ->after('pedido_cupom_id');
            $table->foreignId('pedido_influenciador_ref_id')->nullable()->after('pedido_valor_desconto_cupom')
                ->constrained('influenciadores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pedido_influenciador_ref_id');
            $table->dropConstrainedForeignId('pedido_cupom_id');
            $table->dropColumn('pedido_valor_desconto_cupom');
        });
    }
};
