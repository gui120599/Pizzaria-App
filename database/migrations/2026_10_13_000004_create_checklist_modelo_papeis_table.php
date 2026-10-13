<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Papéis (roles do Spatie) que executam o modelo. FK para roles.id em vez
     * do nome do papel: renomear uma role não quebra a atribuição.
     */
    public function up(): void
    {
        Schema::create('checklist_modelo_papeis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_modelo_id')->constrained('checklist_modelos')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['checklist_modelo_id', 'role_id'], 'ck_mod_papeis_modelo_role_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_modelo_papeis');
    }
};
