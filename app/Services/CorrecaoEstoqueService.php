<?php

namespace App\Services;

use App\Enums\MovimentacaoTipoEnum;
use App\Models\EstoqueCorrecao;
use App\Models\EstoqueLote;
use App\Models\ItensVenda;
use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige ou exclui retroativamente uma movimentação de estoque lançada
 * errada (ex.: fator de conversão CX→UN esquecido numa compra, ou uma
 * entrada/saída manual — via ação "Movimentar" do Produto — que nem deveria
 * ter existido), quando ela já está confirmada/travada e não dá pra
 * simplesmente editar pela tela normal.
 *
 * Como o custo médio (WAC) é ponderado, mexer só na movimentação errada não
 * basta: tudo que aconteceu DEPOIS dela (entradas e saídas) foi calculado em
 * cima de um saldo/custo médio que também estava errado. Este serviço faz um
 * replay cronológico de todo o histórico do produto, reaplicando a mesma
 * lógica de EstoqueService::registrarEntrada/registrarSaida — substituindo o
 * valor da movimentação apontada (correção) ou removendo-a do cálculo por
 * completo (exclusão) — e devolve/aplica os ajustes em cadeia até o presente.
 *
 * Alcance da correção automática:
 * - MovimentacaoProduto do próprio produto (quantidade/custo/saldo).
 * - EstoqueLote criado pela movimentação (se o produto rastreia lote).
 * - ItensVenda.item_venda_custo_unitario, só quando o produto corrigido é o
 *   próprio item vendido (venda direta). Quando ele foi consumido como
 *   insumo de ficha técnica de outro produto vendido, o custo desse item já
 *   foi calculado somando vários insumos no momento da venda — recalcular
 *   isso exigiria reconstituir o custo histórico de todos os insumos da
 *   ficha, não só deste; por segurança, não mexemos automaticamente nesse
 *   caso, só reportamos como impacto indireto pra revisão manual.
 */
class CorrecaoEstoqueService
{
    /**
     * Calcula o resultado da correção sem gravar nada (dry-run) — usado para
     * mostrar a prévia antes do usuário confirmar.
     */
    public function simular(Produto $produto, MovimentacaoProduto $alvo, float $quantidadeCorreta, float $custoUnitarioCorreto): array
    {
        return $this->replay($produto, $this->historico($produto), $alvo, $quantidadeCorreta, $custoUnitarioCorreto);
    }

    /**
     * Recalcula e persiste a correção dentro de uma transação: movimentações
     * afetadas, lote de origem (se houver), itens_vendas de venda direta,
     * saldo/custo médio do produto, e o log de auditoria.
     */
    public function aplicar(MovimentacaoProduto $alvo, float $quantidadeCorreta, float $custoUnitarioCorreto, ?string $motivo = null): EstoqueCorrecao
    {
        return DB::transaction(function () use ($alvo, $quantidadeCorreta, $custoUnitarioCorreto, $motivo) {
            $produto = Produto::lockForUpdate()->findOrFail($alvo->mov_produto_id);
            $movimentacoes = $this->historico($produto, lock: true);
            $alvo = $movimentacoes->firstWhere('id', $alvo->id) ?? $alvo;

            $resultado = $this->replay($produto, $movimentacoes, $alvo, $quantidadeCorreta, $custoUnitarioCorreto);

            $quantidadeAntiga = (float) $alvo->mov_quantidade;

            $antes = [
                'produto_saldo_estoque' => (float) $produto->produto_saldo_estoque,
                'produto_custo_medio' => (float) $produto->produto_custo_medio,
                'movimentacao_alvo' => [
                    'id' => $alvo->id,
                    'mov_quantidade' => $quantidadeAntiga,
                    'mov_custo_unitario' => (float) $alvo->mov_custo_unitario,
                ],
            ];

            $vendasCorrigidas = $this->persistirResultado($resultado);

            if ($alvo->mov_lote_id) {
                $this->corrigirLote($alvo->mov_lote_id, $quantidadeCorreta - $quantidadeAntiga, $custoUnitarioCorreto);
            }

            $produto->forceFill([
                'produto_saldo_estoque' => round($resultado['produto_saldo_novo'], 3),
                'produto_custo_medio' => round($resultado['produto_custo_medio_novo'], 8),
            ])->save();

            return EstoqueCorrecao::create([
                'ec_produto_id' => $produto->id,
                'ec_movimentacao_id' => $alvo->id,
                'ec_user_id' => Auth::id(),
                'ec_motivo' => $motivo,
                'ec_dados_antes' => $antes,
                'ec_dados_depois' => [
                    'produto_saldo_estoque' => round($resultado['produto_saldo_novo'], 3),
                    'produto_custo_medio' => round($resultado['produto_custo_medio_novo'], 8),
                    'movimentacao_alvo' => [
                        'mov_quantidade' => round($quantidadeCorreta, 3),
                        'mov_custo_unitario' => round($custoUnitarioCorreto, 8),
                    ],
                    'vendas_corrigidas' => $vendasCorrigidas,
                ],
            ]);
        });
    }

    /**
     * Dry-run de excluir uma movimentação por completo (quando ela não devia
     * ter existido — ex.: lançamento manual duplicado/errado feito pela ação
     * "Movimentar" do Produto, sem relação com nenhuma compra/venda/balanço).
     */
    public function simularExclusao(Produto $produto, MovimentacaoProduto $alvo): array
    {
        return $this->replay($produto, $this->historico($produto), $alvo, null, null);
    }

    /**
     * Exclui a movimentação (soft delete — mantém o rastro) e recalcula em
     * cadeia tudo que veio depois, como se ela nunca tivesse existido. Só
     * deve ser oferecido pela UI para movimentações "soltas" — sem
     * referência de compra/balanço nem venda associada; ainda assim,
     * valida de novo aqui por segurança.
     */
    public function aplicarExclusao(MovimentacaoProduto $alvo, ?string $motivo = null): EstoqueCorrecao
    {
        return DB::transaction(function () use ($alvo, $motivo) {
            if ($alvo->mov_referencia_id || $alvo->mov_venda_id) {
                throw ValidationException::withMessages([
                    'movimentacao' => 'Esta movimentação está vinculada a uma compra, balanço ou venda — não pode ser simplesmente excluída. Use "Corrigir" ou ajuste o documento de origem.',
                ]);
            }

            $produto = Produto::lockForUpdate()->findOrFail($alvo->mov_produto_id);
            $movimentacoes = $this->historico($produto, lock: true);
            $alvo = $movimentacoes->firstWhere('id', $alvo->id) ?? $alvo;

            $resultado = $this->replay($produto, $movimentacoes, $alvo, null, null);

            $antes = [
                'produto_saldo_estoque' => (float) $produto->produto_saldo_estoque,
                'produto_custo_medio' => (float) $produto->produto_custo_medio,
                'movimentacao_alvo' => [
                    'id' => $alvo->id,
                    'mov_tipo' => $alvo->mov_tipo->value,
                    'mov_quantidade' => (float) $alvo->mov_quantidade,
                    'mov_custo_unitario' => (float) $alvo->mov_custo_unitario,
                ],
            ];

            $vendasCorrigidas = $this->persistirResultado($resultado);

            if ($alvo->mov_tipo === MovimentacaoTipoEnum::ENTRADA && $alvo->mov_lote_id) {
                $this->removerLoteSeIntacto($alvo->mov_lote_id);
            }

            $produto->forceFill([
                'produto_saldo_estoque' => round($resultado['produto_saldo_novo'], 3),
                'produto_custo_medio' => round($resultado['produto_custo_medio_novo'], 8),
            ])->save();

            $alvo->delete();

            return EstoqueCorrecao::create([
                'ec_produto_id' => $produto->id,
                'ec_movimentacao_id' => $alvo->id,
                'ec_user_id' => Auth::id(),
                'ec_motivo' => $motivo,
                'ec_dados_antes' => $antes,
                'ec_dados_depois' => [
                    'produto_saldo_estoque' => round($resultado['produto_saldo_novo'], 3),
                    'produto_custo_medio' => round($resultado['produto_custo_medio_novo'], 8),
                    'movimentacao_excluida' => true,
                    'vendas_corrigidas' => $vendasCorrigidas,
                ],
            ]);
        });
    }

    /**
     * Histórico completo do produto na ordem real em que as movimentações
     * foram processadas (opcionalmente travado para escrita).
     *
     * Ordena por id, não por mov_data: EstoqueService::registrarEntrada/
     * registrarSaida sempre aplica o produto_custo_medio vigente NO MOMENTO
     * REAL do processamento, nunca reordena o passado pela data de negócio
     * informada. Uma movimentação lançada com data retroativa (ação
     * "Movimentar" com data customizada, importação de XML de NF-e usando a
     * data da nota) ainda assim usou o médio que estava ao vivo quando foi
     * de fato gravada — id (auto-increment, estritamente sequencial na
     * inserção) reflete essa ordem real; mov_data não.
     */
    private function historico(Produto $produto, bool $lock = false): Collection
    {
        $query = MovimentacaoProduto::where('mov_produto_id', $produto->id)
            ->orderBy('id');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /** Grava as movimentações e itens_vendas afetados pelo replay; devolve a lista de vendas corrigidas. */
    private function persistirResultado(array $resultado): array
    {
        foreach ($resultado['linhas'] as $linha) {
            if (! $linha['mudou']) {
                continue;
            }

            $linha['movimentacao']->forceFill([
                'mov_quantidade' => round($linha['quantidade'], 3),
                'mov_custo_unitario' => round($linha['custo_unitario_novo'], 8),
                'mov_custo_total' => round($linha['quantidade'] * $linha['custo_unitario_novo'], 2),
                'mov_saldo_apos' => round($linha['saldo_apos_novo'], 3),
                'mov_custo_medio_apos' => round($linha['custo_medio_apos_novo'], 8),
            ])->save();
        }

        $vendasCorrigidas = [];
        foreach ($resultado['vendas_afetadas'] as $venda) {
            ItensVenda::where('id', $venda['item_venda_id'])->update([
                'item_venda_custo_unitario' => $venda['custo_unitario_novo'],
            ]);
            $vendasCorrigidas[] = $venda;
        }

        return $vendasCorrigidas;
    }

    /**
     * @param  Collection<int, MovimentacaoProduto>  $movimentacoes  histórico completo do produto, em ordem cronológica
     * @param  ?float  $quantidadeCorreta  null (junto com $custoUnitarioCorreto) = excluir a movimentação alvo do cálculo
     */
    private function replay(Produto $produto, Collection $movimentacoes, MovimentacaoProduto $alvo, ?float $quantidadeCorreta, ?float $custoUnitarioCorreto): array
    {
        $excluir = $quantidadeCorreta === null && $custoUnitarioCorreto === null;

        $saldo = 0.0;
        $medio = 0.0;
        $medioInicializado = false;
        $linhas = collect();

        foreach ($movimentacoes as $mov) {
            $ehAlvo = $mov->id === $alvo->id;

            if ($ehAlvo && $excluir) {
                continue;
            }

            if ($mov->mov_tipo === MovimentacaoTipoEnum::ENTRADA) {
                $qtd = $ehAlvo ? $quantidadeCorreta : (float) $mov->mov_quantidade;
                $custoAplicado = $ehAlvo ? $custoUnitarioCorreto : (float) $mov->mov_custo_unitario;
                $novoSaldo = $saldo + $qtd;
                $medio = $novoSaldo > 0 ? (($saldo * $medio) + ($qtd * $custoAplicado)) / $novoSaldo : $custoAplicado;
                $saldo = $novoSaldo;
                $medioInicializado = true;
            } else {
                $qtd = (float) $mov->mov_quantidade;
                // Histórico começa direto numa saída, sem nenhuma entrada
                // antes (ex.: produto cujo custo médio veio de outra fonte —
                // preço de custo cadastrado — nunca de uma entrada real):
                // usa o próprio custo gravado na saída como médio vigente
                // naquele momento, em vez de zerar.
                if (! $medioInicializado) {
                    $medio = (float) $mov->mov_custo_unitario;
                    $medioInicializado = true;
                }
                $custoAplicado = $medio;
                $saldo -= $qtd;
            }

            $custoAntigo = round((float) $mov->mov_custo_unitario, 8);
            $custoNovo = round($custoAplicado, 8);
            $qtdAntiga = round((float) $mov->mov_quantidade, 3);
            $qtdNova = round($qtd, 3);
            $medioAposAntigo = round((float) ($mov->mov_custo_medio_apos ?? 0.0), 8);
            $medioAposNovo = round($medio, 8);

            $linhas->push([
                'movimentacao' => $mov,
                'quantidade' => $qtdNova,
                'custo_unitario_antigo' => $custoAntigo,
                'custo_unitario_novo' => $custoNovo,
                'custo_medio_apos_antigo' => $medioAposAntigo,
                'custo_medio_apos_novo' => $medioAposNovo,
                'saldo_apos_antigo' => (float) $mov->mov_saldo_apos,
                'saldo_apos_novo' => round($saldo, 3),
                'mudou' => $ehAlvo
                    || $qtdAntiga !== $qtdNova
                    || abs($custoAntigo - $custoNovo) >= 0.00000005
                    || abs($medioAposAntigo - $medioAposNovo) >= 0.00000005,
            ]);
        }

        $vendasAfetadas = collect();
        $vendasIndiretasIgnoradas = collect();

        foreach ($linhas as $linha) {
            $mov = $linha['movimentacao'];
            if (! $linha['mudou'] || $mov->mov_tipo !== MovimentacaoTipoEnum::SAIDA || ! $mov->mov_venda_id) {
                continue;
            }

            $itemVenda = ItensVenda::where('item_venda_venda_id', $mov->mov_venda_id)
                ->where('item_venda_produto_id', $produto->id)
                ->first();

            if ($itemVenda) {
                $vendasAfetadas->push([
                    'item_venda_id' => $itemVenda->id,
                    'venda_id' => $mov->mov_venda_id,
                    'custo_unitario_antigo' => (float) $itemVenda->item_venda_custo_unitario,
                    'custo_unitario_novo' => $linha['custo_unitario_novo'],
                ]);
            } else {
                $vendasIndiretasIgnoradas->push([
                    'venda_id' => $mov->mov_venda_id,
                    'movimentacao_id' => $mov->id,
                ]);
            }
        }

        // Não bloqueia (o usuário pode estar corrigindo uma movimentação
        // sabendo que vai reconciliar a diferença com um Balanço físico
        // logo em seguida) — só informa, pra prévia poder avisar.
        $saldoFicariaNegativo = $saldo < -0.0005
            || $linhas->contains(fn (array $l): bool => $l['saldo_apos_novo'] < -0.0005);

        return [
            'excluir' => $excluir,
            'produto_saldo_atual' => (float) $produto->produto_saldo_estoque,
            'produto_saldo_novo' => $saldo,
            'produto_custo_medio_atual' => (float) $produto->produto_custo_medio,
            'produto_custo_medio_novo' => $medio,
            'saldo_ficaria_negativo' => $saldoFicariaNegativo,
            'linhas' => $linhas,
            'linhas_afetadas' => $linhas->filter(fn (array $l) => $l['mudou'])->values(),
            'vendas_afetadas' => $vendasAfetadas,
            'vendas_indiretas_ignoradas' => $vendasIndiretasIgnoradas->unique('venda_id')->values(),
        ];
    }

    /**
     * Ajusta o lote criado pela movimentação corrigida (quando o produto
     * rastreia lote): soma a mesma diferença de quantidade ao inicial/atual
     * — as unidades "a mais" também nunca tinham sido contabilizadas nele —
     * e corrige o custo unitário. Reativa o lote se ele tinha sido marcado
     * esgotado por causa da quantidade menor.
     */
    private function corrigirLote(int $loteId, float $deltaQuantidade, float $custoUnitarioCorreto): void
    {
        $lote = EstoqueLote::lockForUpdate()->find($loteId);
        if (! $lote) {
            return;
        }

        $novoAtual = round((float) $lote->lote_qtd_atual + $deltaQuantidade, 3);

        $lote->forceFill([
            'lote_qtd_inicial' => round((float) $lote->lote_qtd_inicial + $deltaQuantidade, 3),
            'lote_qtd_atual' => max($novoAtual, 0),
            'lote_custo_unitario' => round($custoUnitarioCorreto, 8),
            'lote_status' => $novoAtual > 0 ? 'ativo' : $lote->lote_status,
        ])->save();
    }

    /**
     * Remove o lote criado pela movimentação excluída, só quando ele ainda
     * está intacto (ninguém consumiu nada dele ainda). Se já foi
     * parcialmente baixado por outra saída, mexer nele exigiria decidir o
     * que fazer com esse consumo — fica de fora do escopo automático, o
     * lote permanece como está (órfão de uma entrada que não existe mais)
     * para revisão manual.
     */
    private function removerLoteSeIntacto(int $loteId): void
    {
        $lote = EstoqueLote::lockForUpdate()->find($loteId);
        if (! $lote) {
            return;
        }

        if (abs((float) $lote->lote_qtd_atual - (float) $lote->lote_qtd_inicial) < 0.001) {
            $lote->delete();
        }
    }
}
