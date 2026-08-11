<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sefaz_notas_recebidas', function (Blueprint $table) {
            $table->string('snr_xml_path')->nullable()->after('snr_data_emissao');
        });
    }

    public function down(): void
    {
        Schema::table('sefaz_notas_recebidas', function (Blueprint $table) {
            $table->dropColumn('snr_xml_path');
        });
    }
};
