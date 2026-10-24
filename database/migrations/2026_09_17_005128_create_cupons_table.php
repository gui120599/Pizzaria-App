<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cupom_influenciador_id')->nullable()
                ->constrained('influenciadores')->nullOnDelete();
            $table->string('cupom_origem')->default('interno');
            $table->string('cupom_codigo')->unique();
            $table->string('cupom_tipo_desconto');
            $table->decimal('cupom_valor_desconto', 10, 2);
            $table->decimal('cupom_valor_minimo_pedido', 10, 2)->nullable();
            $table->unsignedInteger('cupom_limite_uso_total')->nullable();
            $table->unsignedInteger('cupom_limite_uso_por_cliente')->default(1);
            $table->date('cupom_data_inicio')->nullable();
            $table->date('cupom_data_expiracao')->nullable();
            $table->boolean('cupom_ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('cupom_ativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cupons');
    }
};
