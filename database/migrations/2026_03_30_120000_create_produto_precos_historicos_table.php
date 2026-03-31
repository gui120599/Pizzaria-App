<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('produto_precos_historicos', function (Blueprint $table) {
            $table->id();
            $table->uuid('lote_uuid')->index();
            $table->string('acao', 100)->index();
            $table->unsignedBigInteger('categoria_id');
            $table->unsignedBigInteger('produto_id');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->decimal('valor_antigo_custo', 10, 2)->nullable();
            $table->decimal('valor_antigo_percentual', 10, 2)->nullable();
            $table->decimal('valor_antigo_venda', 10, 2)->nullable();
            $table->decimal('valor_novo_custo', 10, 2)->nullable();
            $table->decimal('valor_novo_percentual', 10, 2)->nullable();
            $table->decimal('valor_novo_venda', 10, 2)->nullable();
            $table->timestamp('restaurado_em')->nullable();
            $table->unsignedBigInteger('restaurado_por')->nullable();
            $table->timestamps();

            $table->foreign('categoria_id')->references('id')->on('categorias')->onDelete('cascade');
            $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('restaurado_por')->references('id')->on('users')->onDelete('set null');
            $table->index(['categoria_id', 'lote_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produto_precos_historicos');
    }
};
