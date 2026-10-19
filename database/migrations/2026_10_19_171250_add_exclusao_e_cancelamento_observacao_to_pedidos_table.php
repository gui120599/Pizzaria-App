<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('pedido_motivo_exclusao')->nullable();
            $table->text('pedido_exclusao_observacao')->nullable();
            $table->unsignedBigInteger('pedido_usuario_excluiu_id')->nullable();
            $table->text('pedido_motivo_cancelamento_observacao')->nullable();

            $table->foreign('pedido_usuario_excluiu_id', 'pedidos_usuario_excluiu_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropForeign('pedidos_usuario_excluiu_fk');
            $table->dropColumn([
                'deleted_at',
                'pedido_motivo_exclusao',
                'pedido_exclusao_observacao',
                'pedido_usuario_excluiu_id',
                'pedido_motivo_cancelamento_observacao',
            ]);
        });
    }
};
