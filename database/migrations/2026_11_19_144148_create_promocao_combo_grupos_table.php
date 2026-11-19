<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promoção Combo por grupos: "1 Pizza Grande + 1 Pizza Brotinho" tem dois
     * grupos, cada um com quantidade e participantes próprios; o combo fecha
     * quando todos estão completos. Cada promoção que já existe vira um grupo
     * único com a quantidade e os participantes dela (o comportamento não muda).
     */
    public function up(): void
    {
        Schema::create('promocao_combo_grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pcg_promocao_combo_id')->constrained('promocoes_combo')->cascadeOnDelete();
            $table->string('pcg_nome', 60);
            $table->unsignedTinyInteger('pcg_quantidade')->default(1);
            $table->unsignedInteger('pcg_ordem')->default(0);
            $table->timestamps();
        });

        Schema::table('promocao_combo_participantes', function (Blueprint $table) {
            $table->foreignId('pcp_grupo_id')->nullable()->after('pcp_promocao_combo_id')
                ->constrained('promocao_combo_grupos')->cascadeOnDelete();
        });

        $agora = now();

        DB::table('promocoes_combo')->get(['id', 'promocombo_quantidade'])->each(function (object $promocao) use ($agora): void {
            $categoria = DB::table('promocao_combo_participantes')
                ->join('categorias', 'categorias.id', '=', 'promocao_combo_participantes.pcp_categoria_id')
                ->where('pcp_promocao_combo_id', $promocao->id)
                ->where('pcp_excluir', false)
                ->orderBy('promocao_combo_participantes.id')
                ->value('categorias.categoria_nome');

            $grupoId = DB::table('promocao_combo_grupos')->insertGetId([
                'pcg_promocao_combo_id' => $promocao->id,
                'pcg_nome' => mb_substr($categoria ?? 'Itens da promoção', 0, 60),
                'pcg_quantidade' => max(1, (int) $promocao->promocombo_quantidade),
                'pcg_ordem' => 1,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            DB::table('promocao_combo_participantes')->where('pcp_promocao_combo_id', $promocao->id)->update(['pcp_grupo_id' => $grupoId]);
        });
    }

    public function down(): void
    {
        Schema::table('promocao_combo_participantes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pcp_grupo_id');
        });

        Schema::dropIfExists('promocao_combo_grupos');
    }
};
