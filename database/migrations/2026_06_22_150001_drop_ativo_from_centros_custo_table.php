<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Padronizamos ativo/inativo via SoftDeletes (registro deletado = inativo).
        Schema::table('centros_custo', function (Blueprint $table) {
            $table->dropColumn('centro_custo_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('centros_custo', function (Blueprint $table) {
            $table->boolean('centro_custo_ativo')->default(true)->after('centro_custo_tipo');
        });
    }
};
