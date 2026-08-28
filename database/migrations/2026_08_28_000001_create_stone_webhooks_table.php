<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stone_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('stw_evento')->nullable();               // charge.paid / charge.refunded
            $table->string('stw_hook_id')->nullable()->index();     // hook_xxx
            $table->string('stw_charge_id')->nullable()->index();   // ch_xxx
            $table->string('stw_charge_code')->nullable();          // code/NSU da transação
            $table->string('stw_order_id')->nullable()->index();    // or_xxx
            $table->string('stw_order_code')->nullable()->index();  // código curto do pedido
            $table->foreignId('stw_venda_id')->nullable()->constrained('vendas')->nullOnDelete();
            $table->json('stw_payload');
            $table->boolean('stw_autenticado')->nullable();
            $table->timestamp('stw_processado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stone_webhooks');
    }
};
