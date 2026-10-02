<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // "Nome livre" do Painel do Garçom: pessoa da mesa que não quis se
            // cadastrar. Existe como Cliente só para separar a comanda
            // (item_pedido_cliente_id); fica fora das buscas e da listagem.
            $table->boolean('cliente_avulso')->default(false)->after('cliente_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('cliente_avulso');
        });
    }
};
