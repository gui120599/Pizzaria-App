<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            // Código impresso no QR da mesa (/mesa/{codigo}). Aleatório, nunca
            // o id: regerar invalida um QR vazado. Mesa nova recebe o código
            // no Mesa::booted().
            $table->string('mesa_codigo_qr', 16)->nullable()->unique()->after('mesa_area');
            // Desliga o pedido pelo celular só nesta mesa (o QR mostra o cardápio).
            $table->boolean('mesa_pedido_cliente_ativo')->default(true)->after('mesa_codigo_qr');
        });

        // Mesas que já existem (inclusive as excluídas): um código para cada.
        DB::table('mesas')->whereNull('mesa_codigo_qr')->orderBy('id')->pluck('id')->each(function (int $id) {
            do {
                $codigo = Str::lower(Str::random(10));
            } while (DB::table('mesas')->where('mesa_codigo_qr', $codigo)->exists());

            DB::table('mesas')->where('id', $id)->update(['mesa_codigo_qr' => $codigo]);
        });
    }

    public function down(): void
    {
        Schema::table('mesas', function (Blueprint $table) {
            $table->dropUnique(['mesa_codigo_qr']);
            $table->dropColumn(['mesa_codigo_qr', 'mesa_pedido_cliente_ativo']);
        });
    }
};
