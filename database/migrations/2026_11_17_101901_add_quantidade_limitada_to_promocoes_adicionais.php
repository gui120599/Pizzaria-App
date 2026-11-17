<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teto de brindes da campanha inteira (todas as regras e ofertas somadas),
 * nos moldes de promocao_qtd_total/promocao_qtd_vendida da relâmpago.
 * Null = sem limite, que é como todas as campanhas existentes ficam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes_adicionais', function (Blueprint $table) {
            $table->unsignedInteger('promoad_qtd_total')->nullable()->after('promoad_opcoes_entrega');
            $table->decimal('promoad_qtd_vendida', 10, 2)->default(0)->after('promoad_qtd_total');
        });
    }

    public function down(): void
    {
        Schema::table('promocoes_adicionais', function (Blueprint $table) {
            $table->dropColumn(['promoad_qtd_total', 'promoad_qtd_vendida']);
        });
    }
};
