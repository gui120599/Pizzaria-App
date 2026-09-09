<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcoes_entregas', function (Blueprint $table) {
            $table->boolean('opcaoentrega_requer_endereco')->default(false)->after('opcaoentrega_nome');
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_entregas', function (Blueprint $table) {
            $table->dropColumn('opcaoentrega_requer_endereco');
        });
    }
};
