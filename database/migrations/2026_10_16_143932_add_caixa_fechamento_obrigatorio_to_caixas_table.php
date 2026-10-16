<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('caixas', function (Blueprint $table) {
            $table->boolean('caixa_fechamento_obrigatorio')->default(true)->after('caixa_nome');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caixas', function (Blueprint $table) {
            $table->dropColumn('caixa_fechamento_obrigatorio');
        });
    }
};
