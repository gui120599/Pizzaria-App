<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opcoes_entregas', function (Blueprint $table) {
            $table->decimal('opcaoentrega_valor_frete', 8, 2)->default(0)->after('opcaoentrega_nome');
            $table->decimal('opcaoentrega_min_valor_frete', 8, 2)->default(0)->after('opcaoentrega_valor_frete');
        });
    }

    public function down(): void
    {
        Schema::table('opcoes_entregas', function (Blueprint $table) {
            $table->dropColumn(['opcaoentrega_valor_frete', 'opcaoentrega_min_valor_frete']);
        });
    }
};
