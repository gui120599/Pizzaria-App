<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HMAC do PIN do quiosque (mesma ideia do cpf_hash): acha o colaborador
     * pelo PIN digitado sem testar bcrypt contra todos e garante PIN único.
     * PINs gravados antes desta coluna ficam sem lookup até serem trocados.
     */
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->string('pin_lookup', 64)->nullable()->unique()->after('pin_quiosque');
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropUnique(['pin_lookup']);
            $table->dropColumn('pin_lookup');
        });
    }
};
