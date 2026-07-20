<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marcas', function (Blueprint $table) {
            $table->id();
            $table->string('marca_nome');
            $table->string('marca_imagem')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('marca_nome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marcas');
    }
};
