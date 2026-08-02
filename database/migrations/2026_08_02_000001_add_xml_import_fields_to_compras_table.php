<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->string('compra_xml_path')->nullable()->after('compra_chave_nfe');
        });

        Schema::table('compras', function (Blueprint $table) {
            $table->unique('compra_chave_nfe', 'compras_compra_chave_nfe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('compras', function (Blueprint $table) {
            $table->dropUnique('compras_compra_chave_nfe_unique');
            $table->dropColumn('compra_xml_path');
        });
    }
};
