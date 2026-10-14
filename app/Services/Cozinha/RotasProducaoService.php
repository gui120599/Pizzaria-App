<?php

namespace App\Services\Cozinha;

use App\Models\Categoria;
use App\Models\Produto;
use App\Models\RotaProducao;
use Illuminate\Support\Facades\DB;

/**
 * Grava a rota de produção de um produto ou de uma categoria a partir da
 * lista ordenada do formulário: a posição vira a etapa (1, 2, 3...).
 *
 * As linhas existentes são atualizadas no lugar (o histórico da rota segue a
 * mesma linha). Como (produto|categoria, etapa) é único, trocar a ordem de
 * duas etapas colidiria no meio do caminho: as etapas antigas vão antes para
 * uma faixa temporária.
 */
class RotasProducaoService
{
    private const DESLOCAMENTO_TEMPORARIO = 100;

    /**
     * @param  list<array{id?: int|string|null, setor_producao_id: int|string, tempo_previsto_min?: int|string|null, ativo?: bool|null}>  $etapas
     */
    public function sincronizar(Produto|Categoria $dono, array $etapas): void
    {
        $coluna = $dono instanceof Produto ? 'produto_id' : 'categoria_id';

        DB::transaction(function () use ($dono, $etapas, $coluna): void {
            $existentes = RotaProducao::where($coluna, $dono->getKey())->lockForUpdate()->get()->keyBy('id');
            $mantidas = array_filter(array_map(fn (array $etapa) => (int) ($etapa['id'] ?? 0), $etapas));

            foreach ($existentes->except($mantidas) as $removida) {
                $removida->delete();
            }

            RotaProducao::where($coluna, $dono->getKey())
                ->whereKey($mantidas)
                ->update(['etapa' => DB::raw('etapa + '.self::DESLOCAMENTO_TEMPORARIO)]);

            foreach (array_values($etapas) as $indice => $etapa) {
                $rota = $existentes->get((int) ($etapa['id'] ?? 0)) ?? new RotaProducao([$coluna => $dono->getKey()]);

                $rota->fill([
                    'setor_producao_id' => (int) $etapa['setor_producao_id'],
                    'etapa' => $indice + 1,
                    'tempo_previsto_min' => filled($etapa['tempo_previsto_min'] ?? null) ? (int) $etapa['tempo_previsto_min'] : null,
                    'ativo' => (bool) ($etapa['ativo'] ?? true),
                ])->save();
            }
        });
    }

    /**
     * Estado inicial do formulário.
     *
     * @return list<array{id: int, setor_producao_id: int, tempo_previsto_min: ?int, ativo: bool}>
     */
    public function paraFormulario(Produto|Categoria|null $dono): array
    {
        if ($dono === null || ! $dono->exists) {
            return [];
        }

        return $dono->rotasProducao()->get()
            ->map(fn (RotaProducao $rota) => [
                'id' => $rota->id,
                'setor_producao_id' => $rota->setor_producao_id,
                'tempo_previsto_min' => $rota->tempo_previsto_min,
                'ativo' => $rota->ativo,
            ])
            ->all();
    }
}
