<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessao_mesas', function (Blueprint $table) {
            $table->unsignedTinyInteger('sessao_mesa_pessoas')->default(1)->after('sessao_mesa_status');
            // Snapshot do percentual na abertura: mudar a config depois não
            // altera contas já abertas. 0 = sem taxa (removida ou sessão antiga).
            $table->decimal('sessao_mesa_taxa_servico_percentual', 5, 2)->default(0)->after('sessao_mesa_pessoas');
            $table->timestamp('sessao_mesa_conta_solicitada_em')->nullable()->after('sessao_mesa_taxa_servico_percentual');
            // Lock otimista para edições concorrentes de pessoas/taxa.
            $table->unsignedInteger('sessao_mesa_versao')->default(0)->after('sessao_mesa_conta_solicitada_em');
        });
    }

    public function down(): void
    {
        Schema::table('sessao_mesas', function (Blueprint $table) {
            $table->dropColumn([
                'sessao_mesa_pessoas',
                'sessao_mesa_taxa_servico_percentual',
                'sessao_mesa_conta_solicitada_em',
                'sessao_mesa_versao',
            ]);
        });
    }
};
