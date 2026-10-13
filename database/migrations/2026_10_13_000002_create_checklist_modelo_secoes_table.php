<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_modelo_secoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_modelo_id')->constrained('checklist_modelos')->cascadeOnDelete();
            $table->string('titulo', 120);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['checklist_modelo_id', 'ordem'], 'ck_mod_secoes_modelo_ordem_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_modelo_secoes');
    }
};
