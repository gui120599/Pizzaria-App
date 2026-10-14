<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Copia as linhas de produção (filtro do Painel de Pedidos) para setores
     * e os vínculos categoria→linha para rotas de categoria (etapa 1). As
     * tabelas antigas ficam intactas.
     *
     * Uma categoria ligada a várias linhas fica com a linha de menor id (a
     * rota tem um setor por etapa); as demais vão para o log para o admin
     * revisar no cadastro de rotas.
     */
    public function up(): void
    {
        if (! Schema::hasTable('linhas_producao') || DB::table('setores_producao')->exists()) {
            return;
        }

        $agora = now();
        $setorPorLinha = [];

        foreach (DB::table('linhas_producao')->orderBy('id')->get() as $ordem => $linha) {
            $setorPorLinha[$linha->id] = DB::table('setores_producao')->insertGetId([
                'nome' => Str::limit($linha->linha_nome, 60, ''),
                'slug' => Str::limit(Str::slug($linha->linha_nome), 60, ''),
                'tipo' => 'producao',
                'cor' => '#f97316',
                'ordem' => $ordem + 1,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }

        $vinculos = DB::table('categoria_linha_producao')
            ->orderBy('categoria_id')
            ->orderBy('linha_producao_id')
            ->get()
            ->groupBy('categoria_id');

        foreach ($vinculos as $categoriaId => $linhas) {
            DB::table('rotas_producao')->insert([
                'categoria_id' => $categoriaId,
                'setor_producao_id' => $setorPorLinha[$linhas->first()->linha_producao_id],
                'etapa' => 1,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            if ($linhas->count() > 1) {
                Log::warning('Cozinha: categoria em várias linhas de produção; rota criada só com a primeira.', [
                    'categoria_id' => $categoriaId,
                    'linhas_ignoradas' => $linhas->skip(1)->pluck('linha_producao_id')->all(),
                ]);
            }
        }
    }

    /** Os dados copiados saem junto com as tabelas, nas migrations anteriores. */
    public function down(): void {}
};
