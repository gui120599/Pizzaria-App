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
        Schema::create('combo_produtos', function (Blueprint $table) {
            $table->id();
            $table->string('combo_produto_nome');
            $table->boolean('combo_produto_cardapio')->default('false')->nullable();
            $table->boolean('combo_produto_promocional')->default('false')->nullable();
            $table->string('combo_produto_foto')->nullable();
            $table->decimal('combo_produto_valor',10,2)->default('0.00')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_produtos');
    }
};
