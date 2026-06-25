<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimentacoes_balanco', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mbal_produto_id')
                ->constrained('produtos')
                ->cascadeOnDelete();
            $table->foreignId('mbal_usuario_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->decimal('mbal_quantidade_sistema', 12, 3);
            $table->decimal('mbal_quantidade_balanco', 12, 3);
            $table->decimal('mbal_quantidade_ajuste', 12, 3);
            $table->string('mbal_tipo_movimentacao', 10); // ENTRADA | SAIDA
            $table->text('mbal_observacao')->nullable();
            $table->datetime('mbal_data_balanco');
            $table->timestamps();
            $table->softDeletes();

            $table->index('mbal_produto_id');
            $table->index('mbal_data_balanco');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentacoes_balanco');
    }
};
