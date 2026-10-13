<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_execucao_item_id')
                ->constrained('checklist_execucao_itens', 'id', 'ck_anexos_execucao_item_fk')
                ->cascadeOnDelete();
            $table->string('disco', 30);
            $table->string('caminho', 255);
            $table->unsignedInteger('tamanho_bytes')->default(0);
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_anexos');
    }
};
