<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_modelo_itens', function (Blueprint $table) {
            $table->string('criterio', 200)->nullable()->after('instrucao');
            $table->boolean('exige_foto_estoque')->default(false)->after('exige_foto');
            $table->json('dias_semana')->nullable()->after('ordem');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_modelo_itens', function (Blueprint $table) {
            $table->dropColumn(['criterio', 'exige_foto_estoque', 'dias_semana']);
        });
    }
};
