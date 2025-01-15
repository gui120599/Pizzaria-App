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
        Schema::create('adicionais_produtos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ap_adicional_id')->nullable();
            $table->unsignedBigInteger('ap_produto_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('ap_adicional_id')->references('id')->on('adicionais');
            $table->foreign('ap_produto_id')->references('id')->on('produtos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adicionais_produtos');
    }
};
