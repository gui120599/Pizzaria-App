<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materiais_divulgacao', function (Blueprint $table) {
            $table->id();
            $table->string('material_titulo');
            $table->text('material_descricao')->nullable();
            $table->string('material_tipo');
            $table->string('material_arquivo_path')->nullable();
            $table->text('material_conteudo_texto')->nullable();
            $table->boolean('material_ativo')->default(true);
            $table->unsignedInteger('material_ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiais_divulgacao');
    }
};
