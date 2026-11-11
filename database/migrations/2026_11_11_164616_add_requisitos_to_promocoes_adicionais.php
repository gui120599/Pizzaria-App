<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes_adicionais', function (Blueprint $table) {
            $table->foreignId('promoad_categoria_exigida_id')->nullable()->after('promoad_aplica_fracionado')
                ->constrained('categorias')->nullOnDelete();
            $table->json('promoad_opcoes_entrega')->nullable()->after('promoad_categoria_exigida_id');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_adicionais', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promoad_categoria_exigida_id');
            $table->dropColumn('promoad_opcoes_entrega');
        });
    }
};
