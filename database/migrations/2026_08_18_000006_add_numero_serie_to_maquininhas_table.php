<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maquininhas', function (Blueprint $table) {
            $table->string('numero_serie')->nullable()->after('operadora');
        });
    }

    public function down(): void
    {
        Schema::table('maquininhas', function (Blueprint $table) {
            $table->dropColumn('numero_serie');
        });
    }
};
