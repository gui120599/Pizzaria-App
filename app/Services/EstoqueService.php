<?php

namespace App\Services;

use App\Enums\EstoqueModoControleEnum;
use App\Enums\MovimentacaoOrigemEnum;
use App\Enums\MovimentacaoTipoEnum;
use App\Exceptions\EstoqueInsuficienteException;
use App\Models\EstoqueLote;
use App\Models\FichaTecnicaItem;
use App\Models\MovimentacaoProduto;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ponto único de escrita do estoque.
 *
 * Valoração por Custo Médio Móvel Ponderado (WAC): toda entrada recalcula o
 * custo médio do produto; toda saída é valorada pelo médio vigente. Quando o
 * produto rastreia lote (Produto::rastreiaLote() — controla lote/validade OU
 * apenas marca), o físico é baixado em FEFO (vence primeiro, sai primeiro;
 * sem validade cadastrada equivale a FIFO) gerando uma movimentação por lote
 * consumido.
 *
 * $opts aceito (todas opcionais): validade, lote_codigo, marca_id, centro_custo_id,
 * referencia (Model), motivo, data, venda_id, user_id.
 */
class EstoqueService
{
    /**
     * Registra uma entrada de estoque, recalcula o custo médio (WAC) e,
     * se o produto controla lote, cria o lote correspondente.
     */
    public function registrarEntrada(
        Produto $produto,
        float $quantidade,
        float $custoUnitario,
        MovimentacaoOrigemEnum $origem,
        array $opts = []
    ): MovimentacaoProduto {
        return DB::transaction(function () use ($produto, $quantidade, $custoUnitario, $origem, $opts) {
            /** @var Produto $produto */
            $produto = Produto::lockForUpdate()->findOrFail($produto->id);

            $saldoAtual = (float) $produto->produto_saldo_estoque;
            $medioAtual = (float) $produto->produto_custo_medio;
            $novoSaldo = $saldoAtual + $quantidade;

            $novoMedio = $novoSaldo > 0
                ? (($saldoAtual * $medioAtual) + ($quantidade * $custoUnitario)) / $novoSaldo
                : $custoUnitario;

            $lote = null;
            if ($produto->rastreiaLote()) {
                $lote = EstoqueLote::create([
                    'lote_produto_id' => $produto->id,
                    'lote_codigo' => $opts['lote_codigo'] ?? null,
                    'lote_marca_id' => $opts['marca_id'] ?? null,
                    'lote_validade' => $opts['validade'] ?? null,
                    'lote_qtd_inicial' => $quantidade,
                    'lote_qtd_atual' => $quantidade,
                    'lote_custo_unitario' => round($custoUnitario, 8),
                    'lote_data_entrada' => $opts['data'] ?? now(),
                    'lote_status' => 'ativo',
                ]);
            }

            $produto->update([
                'produto_saldo_estoque' => round($novoSaldo, 3),
                'produto_custo_medio' => round($novoMedio, 8),
            ]);

            return $this->criarMovimentacao(
                produto: $produto,
                tipo: MovimentacaoTipoEnum::ENTRADA,
                origem: $origem,
                quantidade: $quantidade,
                custoUnitario: $custoUnitario,
                saldoApos: $novoSaldo,
                lote: $lote,
                opts: $opts,
            );
        });
    }

    /**
     * Registra uma saída de estoque valorada pelo custo médio vigente.
     * Com controle de lote, baixa em FEFO (uma movimentação por lote).
     *
     * @return Collection<int, MovimentacaoProduto>
     */
    public function registrarSaida(
        Produto $produto,
        float $quantidade,
        MovimentacaoOrigemEnum $origem,
        array $opts = []
    ): Collection {
        return DB::transaction(function () use ($produto, $quantidade, $origem, $opts) {
            /** @var Produto $produto */
            $produto = Produto::lockForUpdate()->findOrFail($produto->id);

            $custoMedio = (float) $produto->produto_custo_medio;
            $saldoCorrente = (float) $produto->produto_saldo_estoque;
            $restante = $quantidade;
            $movimentacoes = collect();

            if ($produto->rastreiaLote()) {
                $lotes = EstoqueLote::where('lote_produto_id', $produto->id)
                    ->ativos()
                    ->fefo()
                    ->lockForUpdate()
                    ->get();

                foreach ($lotes as $lote) {
                    if ($restante <= 0) {
                        break;
                    }

                    $consumir = min($restante, (float) $lote->lote_qtd_atual);
                    $lote->lote_qtd_atual = round((float) $lote->lote_qtd_atual - $consumir, 3);
                    if ($lote->lote_qtd_atual <= 0) {
                        $lote->lote_status = 'esgotado';
                    }
                    $lote->save();

                    $saldoCorrente -= $consumir;
                    $restante -= $consumir;

                    $movimentacoes->push($this->criarMovimentacao(
                        produto: $produto,
                        tipo: MovimentacaoTipoEnum::SAIDA,
                        origem: $origem,
                        quantidade: $consumir,
                        custoUnitario: $custoMedio,
                        saldoApos: $saldoCorrente,
                        lote: $lote,
                        opts: $opts,
                    ));
                }
            }

            // Remanescente (produto sem lote, ou estoque de lote insuficiente).
            if ($restante > 0) {
                $saldoCorrente -= $restante;
                $movimentacoes->push($this->criarMovimentacao(
                    produto: $produto,
                    tipo: MovimentacaoTipoEnum::SAIDA,
                    origem: $origem,
                    quantidade: $restante,
                    custoUnitario: $custoMedio,
                    saldoApos: $saldoCorrente,
                    lote: null,
                    opts: $opts,
                ));
            }

            // Custo médio não muda na saída; apenas o saldo.
            $produto->update(['produto_saldo_estoque' => round($saldoCorrente, 3)]);

            return $movimentacoes;
        });
    }

    /**
     * Ajusta o saldo do produto para uma quantidade contada (inventário),
     * gerando entrada ou saída conforme a diferença.
     *
     * @return MovimentacaoProduto|Collection<int, MovimentacaoProduto>|null
     */
    public function registrarAjuste(
        Produto $produto,
        float $quantidadeContada,
        array $opts = []
    ): MovimentacaoProduto|Collection|null {
        $origem = $opts['origem'] ?? MovimentacaoOrigemEnum::INVENTARIO;
        $diferenca = round($quantidadeContada - (float) $produto->produto_saldo_estoque, 3);

        if ($diferenca > 0) {
            return $this->registrarEntrada($produto, $diferenca, (float) $produto->produto_custo_medio, $origem, $opts);
        }

        if ($diferenca < 0) {
            return $this->registrarSaida($produto, abs($diferenca), $origem, $opts);
        }

        return null;
    }

    /**
     * Expande um produto na lista de itens efetivamente consumidos do
     * estoque para a quantidade informada. Única fonte dessa expansão —
     * tanto a baixa real (PedidoObserver/VendaObserver) quanto a checagem de
     * disponibilidade e a produção de lote (registrarProducao) usam este
     * método, para nunca divergirem entre si.
     *
     * Sem ficha técnica, retorna o próprio produto. Com ficha, cada insumo
     * vira uma linha — exceto quando o insumo é ele mesmo produzido
     * internamente (tem ficha própria) e NÃO controla saldo próprio: nesse
     * caso é "virtual" e a expansão continua recursivamente até a matéria-
     * prima real. Um insumo produzido que controla estoque (lote batido via
     * registrarProducao) é tratado como folha — baixa do saldo já produzido,
     * sem re-explodir a ficha a cada venda.
     *
     * @param  array<int>  $visitados  ids já visitados (proteção contra ciclo)
     * @return Collection<int, array{produto: Produto, quantidade: float}>
     */
    public function itensConsumo(Produto $produto, float $quantidade, array $visitados = []): Collection
    {
        if (in_array($produto->id, $visitados, true)) {
            return collect([['produto' => $produto, 'quantidade' => round($quantidade, 3)]]);
        }

        $fichaItens = $produto->relationLoaded('fichaItens')
            ? $produto->fichaItens
            : $produto->fichaItens()->with('insumo')->get();

        if ($fichaItens->isEmpty()) {
            return collect([['produto' => $produto, 'quantidade' => round($quantidade, 3)]]);
        }

        $visitados[] = $produto->id;
        $rendimento = (float) ($produto->produto_ficha_rendimento ?: 1);

        return $fichaItens
            ->filter(fn (FichaTecnicaItem $item) => $item->insumo !== null)
            ->flatMap(function (FichaTecnicaItem $item) use ($quantidade, $rendimento, $visitados) {
                $insumo = $item->insumo;
                $qtdInsumo = round($quantidade * (float) $item->fti_quantidade * $item->fatorPerda() / $rendimento, 3);

                if ($insumo->produto_controla_estoque) {
                    return [['produto' => $insumo, 'quantidade' => $qtdInsumo]];
                }

                return $this->itensConsumo($insumo, $qtdInsumo, $visitados);
            })
            ->values();
    }

    /**
     * Registra a produção de um lote de um insumo produzido (ex.: massa,
     * muçarela ralada): consome os itens da ficha técnica na quantidade
     * necessária (via itensConsumo, então também respeita insumos
     * semi-acabados) e credita a quantidade produzida no saldo do próprio
     * produto — criando lote com validade se ele controla lote.
     *
     * Só faz sentido para produtos que controlam saldo próprio: um insumo
     * "virtual" (produto_controla_estoque = false) nunca acumula estoque —
     * ele é sempre recalculado na hora do consumo, via itensConsumo.
     *
     * Valida a disponibilidade das matérias-primas antes de baixar — a
     * garantia fica no serviço, não em quem chama (mesmo motivo de
     * itensConsumo ser fonte única: produção acionada por outro caminho que
     * não a tela de edição não pode pular o bloqueio).
     *
     * @throws \App\Exceptions\EstoqueInsuficienteException se alguma matéria-prima em modo BLOQUEAR não tiver saldo suficiente
     */
    public function registrarProducao(
        Produto $produto,
        float $quantidade,
        array $opts = []
    ): MovimentacaoProduto {
        if (! $produto->produto_controla_estoque) {
            throw new \InvalidArgumentException("{$produto->produto_descricao} não controla estoque próprio — não há saldo para produzir.");
        }

        if (! $produto->temFichaTecnica()) {
            throw new \InvalidArgumentException("{$produto->produto_descricao} não possui ficha técnica.");
        }

        $this->validarDisponibilidade($produto, $quantidade);

        return DB::transaction(function () use ($produto, $quantidade, $opts) {
            $motivo = $opts['motivo'] ?? "Produção — {$produto->produto_descricao}";

            foreach ($this->itensConsumo($produto, $quantidade) as $consumo) {
                $insumo = $consumo['produto'];

                if (! $insumo->produto_controla_estoque) {
                    continue;
                }

                $this->registrarSaida($insumo, $consumo['quantidade'], MovimentacaoOrigemEnum::PRODUCAO, [
                    'referencia' => $produto,
                    'motivo' => $motivo,
                    'centro_custo_id' => $opts['centro_custo_id'] ?? null,
                    'user_id' => $opts['user_id'] ?? null,
                    'data' => $opts['data'] ?? null,
                ]);
            }

            return $this->registrarEntrada(
                $produto,
                $quantidade,
                round($produto->custoUnitario(), 8),
                MovimentacaoOrigemEnum::PRODUCAO,
                $opts,
            );
        });
    }

    /**
     * Verifica se há saldo suficiente para consumir a quantidade informada
     * do produto (ou dos insumos da ficha técnica, se houver). Itens que não
     * controlam estoque ou estão em modo NAO_CONTROLAR nunca entram no
     * resultado.
     *
     * @return array{bloqueios: array<int, string>, avisos: array<int, string>}
     */
    public function checarDisponibilidade(Produto $produto, float $quantidade): array
    {
        $bloqueios = [];
        $avisos = [];

        foreach ($this->itensConsumo($produto, $quantidade) as $item) {
            /** @var Produto $insumo */
            $insumo = $item['produto'];
            $modo = $insumo->produto_modo_controle_estoque ?? EstoqueModoControleEnum::NAO_CONTROLAR;

            if (! $insumo->produto_controla_estoque || $modo === EstoqueModoControleEnum::NAO_CONTROLAR) {
                continue;
            }

            $faltante = round($item['quantidade'] - (float) $insumo->produto_saldo_estoque, 3);
            if ($faltante <= 0) {
                continue;
            }

            $mensagem = sprintf(
                '%s: saldo insuficiente (disponível %s, necessário %s %s)',
                $insumo->produto_descricao,
                number_format((float) $insumo->produto_saldo_estoque, 3, ',', '.'),
                number_format($item['quantidade'], 3, ',', '.'),
                $insumo->produto_unidade_estoque ?? '',
            );

            if ($modo === EstoqueModoControleEnum::BLOQUEAR) {
                $bloqueios[] = $mensagem;
            } else {
                $avisos[] = $mensagem;
            }
        }

        return ['bloqueios' => $bloqueios, 'avisos' => $avisos];
    }

    /**
     * Lança EstoqueInsuficienteException se algum item (produto ou insumo da
     * ficha) em modo BLOQUEAR não tiver saldo suficiente. Retorna os avisos
     * não bloqueantes (modo AVISAR) para o chamador exibir.
     *
     * @return array<int, string> avisos
     */
    public function validarDisponibilidade(Produto $produto, float $quantidade): array
    {
        $resultado = $this->checarDisponibilidade($produto, $quantidade);

        if ($resultado['bloqueios'] !== []) {
            throw new EstoqueInsuficienteException(implode(' | ', $resultado['bloqueios']));
        }

        return $resultado['avisos'];
    }

    private function criarMovimentacao(
        Produto $produto,
        MovimentacaoTipoEnum $tipo,
        MovimentacaoOrigemEnum $origem,
        float $quantidade,
        float $custoUnitario,
        float $saldoApos,
        ?EstoqueLote $lote,
        array $opts,
    ): MovimentacaoProduto {
        $data = $opts['data'] ?? null;
        if ($data && ! $data instanceof Carbon) {
            $data = Carbon::parse($data);
        }

        $movimentacao = new MovimentacaoProduto([
            'mov_produto_id' => $produto->id,
            'mov_quantidade' => round($quantidade, 3),
            'mov_custo_unitario' => round($custoUnitario, 8),
            'mov_custo_total' => round($quantidade * $custoUnitario, 2),
            'mov_tipo' => $tipo,
            'mov_origem' => $origem,
            'mov_saldo_apos' => round($saldoApos, 3),
            'mov_data' => $data ?? now(),
            'mov_motivo' => $opts['motivo'] ?? null,
            'mov_venda_id' => $opts['venda_id'] ?? null,
            'mov_user_id' => $opts['user_id'] ?? null,
            'mov_centro_custo_id' => $opts['centro_custo_id'] ?? null,
            'mov_lote_id' => $lote?->id,
        ]);

        if (! empty($opts['referencia']) && $opts['referencia'] instanceof Model) {
            $movimentacao->referencia()->associate($opts['referencia']);
        }

        $movimentacao->save();

        return $movimentacao;
    }
}
