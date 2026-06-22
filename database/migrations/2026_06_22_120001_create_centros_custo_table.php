<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centros_custo', function (Blueprint $table) {
            $table->id();
            $table->string('centro_custo_nome');
            $table->string('centro_custo_tipo')->nullable();
            $table->boolean('centro_custo_ativo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('centro_custo_ativo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centros_custo');
    }
};
