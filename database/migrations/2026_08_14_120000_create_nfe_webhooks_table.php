<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfe_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('nfw_evento')->nullable();
            $table->string('nfw_invoice_id')->nullable()->index();
            $table->foreignId('nfw_venda_id')->nullable()->constrained('vendas')->nullOnDelete();
            $table->json('nfw_payload');
            $table->boolean('nfw_assinatura_valida')->nullable();
            $table->timestamp('nfw_processado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfe_webhooks');
    }
};
