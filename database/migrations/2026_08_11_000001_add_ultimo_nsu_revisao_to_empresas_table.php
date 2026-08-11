<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->unsignedBigInteger('empresa_sefaz_ultimo_nsu_revisao')->default(0);
            $table->timestamp('empresa_sefaz_ultima_revisao_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn([
                'empresa_sefaz_ultimo_nsu_revisao',
                'empresa_sefaz_ultima_revisao_em',
            ]);
        });
    }
};
