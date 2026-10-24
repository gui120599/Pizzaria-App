<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamentos_comissao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pgcom_influenciador_id')->constrained('influenciadores')->cascadeOnDelete();
            $table->date('pgcom_periodo_referencia');
            $table->decimal('pgcom_valor_total', 10, 2);
            $table->string('pgcom_forma');
            $table->string('pgcom_comprovante_path')->nullable();
            $table->timestamp('pgcom_pago_em');
            $table->foreignId('pgcom_cupom_credito_id')->nullable()->constrained('cupons')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos_comissao');
    }
};
