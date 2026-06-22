<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_prestador_id')->nullable()->constrained('prestadores')->nullOnDelete();
            $table->string('compra_numero')->nullable();
            $table->string('compra_serie')->nullable();
            $table->string('compra_chave_nfe', 44)->nullable();
            $table->date('compra_data_emissao')->nullable();
            $table->date('compra_data_entrada')->nullable();
            $table->decimal('compra_valor_produtos', 12, 2)->default(0);
            $table->decimal('compra_valor_frete', 12, 2)->default(0);
            $table->decimal('compra_valor_desconto', 12, 2)->default(0);
            $table->decimal('compra_valor_outros', 12, 2)->default(0);
            $table->decimal('compra_valor_total', 12, 2)->default(0);
            $table->string('compra_status')->default('rascunho'); // rascunho | confirmada | cancelada
            $table->string('compra_origem')->default('manual');   // manual | xml
            $table->foreignId('compra_centro_custo_id')->nullable()->constrained('centros_custo')->nullOnDelete();
            $table->text('compra_observacao')->nullable();
            $table->foreignId('compra_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('compra_confirmada_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('compra_status');
            $table->index('compra_chave_nfe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
