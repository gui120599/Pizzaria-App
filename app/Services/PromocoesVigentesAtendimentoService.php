<?php

namespace App\Services;

use App\Enums\ProdutoTipoEnum;
use App\Models\OpcoesEntregas;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use App\Models\PromocaoCombo;
use App\Models\PromocaoRelampago;
use App\Models\PromocaoRelampagoProduto;
use App\Services\PromocaoCombo\PromocaoComboService;
use Illuminate\Support\Collection;

/**
 * Promoções vigentes agora, resumidas para o atendente (aba "Promoções" do
 * catálogo no Atender Pedido): relâmpago, produto adicional e combo, cada uma
 * com as regras em texto curto e os produtos que o seletor consegue lançar.
 * Só leitura: o lançamento segue o fluxo normal do produto, que reprecifica.
 *
 * @phpstan-type ProdutoCartao array{id: int, nome: string, categoria: ?string, foto: ?string, preco: float, preco_original: float}
 * @phpstan-type Cartao array{tipo: string, chave: string, nome: string, descricao: ?string, destaque: ?string, vigencia: string, restricoes: list<string>, brindes: list<array{nome: string, valor: float}>, produtos: list<ProdutoCartao>}
 */
class PromocoesVigentesAtendimentoService
{
    /** @var Collection<int, Produto>|null produtos vendáveis no seletor, por id */
    private ?Collection $produtos = null;

    /** @var array<int, string>|null */
    private ?array $nomesEntrega = null;

    public function __construct(
        private readonly PrecificadorService $precificador,
        private readonly PromocaoComboService $combos,
    ) {}

    /**
     * @return list<Cartao>
     */
    public function listar(bool $mesa = false): array
    {
        return array_values(array_filter(
            [...$this->relampagos(), ...$this->adicionais($mesa), ...$this->combos($mesa)],
            fn (array $cartao): bool => $cartao['produtos'] !== [],
        ));
    }

    /** @return list<Cartao> */
    private function relampagos(): array
    {
        return $this->precificador->promocoesVigentes()
            ->map(fn (PromocaoRelampago $promocao): array => [
                'tipo' => 'relampago',
                'chave' => 'relampago-'.$promocao->id,
                'nome' => $promocao->promocao_nome,
                'descricao' => $promocao->promocao_descricao,
                'destaque' => null,
                'vigencia' => $promocao->textoVigencia(),
                'restricoes' => array_values(array_filter([
                    $promocao->promocao_limite_por_pedido ? 'Máx. '.$this->numero($promocao->promocao_limite_por_pedido).' por pedido' : null,
                    $promocao->promocao_qtd_total !== null ? 'Restam '.$this->numero($promocao->saldoDisponivel()) : null,
                    $this->textoEntrega((array) $promocao->promocao_opcoes_entrega),
                ])),
                'brindes' => [],
                'produtos' => $this->cartoesDeProdutos(
                    $promocao->promocaoProdutos
                        ->reject(fn (PromocaoRelampagoProduto $prp): bool => $prp->esgotado())
                        ->pluck('prp_produto_id'),
                ),
            ])
            ->values()
            ->all();
    }

    /** @return list<Cartao> */
    private function adicionais(bool $mesa): array
    {
        return $this->precificador->regrasAdicionaisVigentes()
            ->filter(fn (PromocaoAdicionalRegra $regra): bool => $regra->promocao->entregaPermitida(null, $mesa))
            ->groupBy('par_promocao_id')
            ->map(function (Collection $regras): array {
                /** @var PromocaoAdicional $promocao */
                $promocao = $regras->first()->promocao;
                $regras = $regras->sortBy([['par_ordem', 'asc'], ['id', 'asc']])->values();
                $limites = $regras->pluck('par_qtd_maxima_por_pedido')->unique();

                $brindes = $regras
                    ->flatMap(fn (PromocaoAdicionalRegra $regra) => $regra->ofertasDisponiveis())
                    ->unique('pao_produto_oferta_id')
                    ->map(fn (PromocaoAdicionalOferta $oferta): array => [
                        'nome' => $oferta->produtoOferta?->produto_descricao ?? '—',
                        'valor' => round((float) $oferta->pao_valor_adicional, 2),
                    ])
                    ->values()
                    ->all();

                return [
                    'ordem' => (int) $promocao->promoad_ordem,
                    'tipo' => 'adicional',
                    'chave' => 'adicional-'.$promocao->id,
                    'nome' => $promocao->promoad_nome,
                    'descricao' => $promocao->promoad_descricao,
                    'destaque' => null,
                    'vigencia' => $promocao->textoVigencia(),
                    'restricoes' => array_values(array_filter([
                        $promocao->promoad_categoria_exigida_id
                            ? 'Exige 1 '.($promocao->categoriaExigida?->categoria_nome ?? 'item da categoria').' no pedido'
                            : null,
                        $limites->count() === 1 && $limites->first() !== null
                            ? 'Máx. '.$this->numero($limites->first()).' por pedido'
                            : null,
                        $promocao->promoad_qtd_total !== null
                            ? 'Restam '.$this->numero(max(0, (float) $promocao->promoad_qtd_total - (float) $promocao->promoad_qtd_vendida)).' brindes'
                            : null,
                        $this->textoEntrega((array) $promocao->promoad_opcoes_entrega),
                        $promocao->opcoesPagamento->isNotEmpty()
                            ? 'Pagamento: '.$promocao->opcoesPagamento->pluck('opcaopag_nome')->implode(', ')
                            : null,
                    ])),
                    'brindes' => $brindes,
                    'produtos' => $this->cartoesDeProdutos($regras->pluck('par_produto_gatilho_id')),
                ];
            })
            ->sortBy('ordem')
            ->map(fn (array $cartao): array => collect($cartao)->except('ordem')->all())
            ->values()
            ->all();
    }

    /** @return list<Cartao> */
    private function combos(bool $mesa): array
    {
        if (! PromocaoCombo::query()->vigente()->exists()) {
            return [];
        }

        $produtos = $this->produtosVendaveis()
            ->map(fn (Produto $produto): array => [
                'categoria_id' => (int) $produto->produto_categoria_id,
                'relampago' => $this->precificador->promocoesVigentesDoProduto($produto->id)->isNotEmpty(),
            ])
            ->all();

        $vitrine = $this->combos->paraCardapio($produtos, $mesa);
        $promocoes = PromocaoCombo::whereIn('id', array_column($vitrine, 'promocaoId'))->get()->keyBy('id');

        return array_map(function (array $combo) use ($promocoes): array {
            /** @var PromocaoCombo|null $promocao */
            $promocao = $promocoes->get($combo['promocaoId']);

            return [
                'tipo' => 'combo',
                'chave' => 'combo-'.$combo['promocaoId'],
                'nome' => $combo['nome'],
                'descricao' => $combo['descricao'],
                'destaque' => $combo['composicao'].' por R$ '.number_format($combo['preco'], 2, ',', '.'),
                'vigencia' => $promocao?->textoVigencia() ?? '',
                'restricoes' => array_values(array_filter([
                    $promocao?->promocombo_limite_por_pedido ? 'Máx. '.$promocao->promocombo_limite_por_pedido.' por pedido' : null,
                    $combo['restam'] !== null ? 'Restam '.$combo['restam'].' combos' : null,
                    $this->textoEntrega((array) $promocao?->promocombo_opcoes_entrega),
                ])),
                'brindes' => [],
                'produtos' => $this->cartoesDeProdutos(collect($combo['produtos'])),
            ];
        }, $vitrine);
    }

    /**
     * @param  Collection<int, int|string>  $ids
     * @return list<ProdutoCartao>
     */
    private function cartoesDeProdutos(Collection $ids): array
    {
        $produtos = $this->produtosVendaveis();

        return $ids->map(fn ($id) => (int) $id)
            ->unique()
            ->map(fn (int $id): ?Produto => $produtos->get($id))
            ->filter()
            ->map(fn (Produto $produto): array => [
                'id' => $produto->id,
                'nome' => $produto->produto_descricao,
                // Mesmo nome de produto em categorias diferentes (Grande, Média, Brotinho).
                'categoria' => $produto->categoria?->categoria_nome,
                'foto' => $produto->getMiniaturaUrl(56),
                'preco' => round($this->precificador->resolver($produto)->precoFinal(), 2),
                'preco_original' => round((float) $produto->produto_preco_venda, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Mesmos produtos que a grade do PedidoProdutoSelector lança.
     *
     * @return Collection<int, Produto>
     */
    private function produtosVendaveis(): Collection
    {
        return $this->produtos ??= Produto::with('categoria')
            ->whereIn('produto_tipo', [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value])
            ->where('produto_cardapio_garcom', true)
            ->orderBy('produto_ordem')
            ->orderBy('produto_descricao')
            ->get()
            ->keyBy('id');
    }

    /** @param  array<int, int|string|null>  $ids */
    private function textoEntrega(array $ids): ?string
    {
        $ids = array_map('intval', array_filter($ids));

        if ($ids === []) {
            return null;
        }

        $this->nomesEntrega ??= OpcoesEntregas::pluck('opcaoentrega_nome', 'id')->all();

        $nomes = array_values(array_filter(array_map(fn (int $id) => $this->nomesEntrega[$id] ?? null, $ids)));

        return $nomes === [] ? null : 'Só '.implode(', ', $nomes);
    }

    private function numero(float|int|null $valor): string
    {
        return number_format((float) $valor, fmod((float) $valor, 1.0) === 0.0 ? 0 : 2, ',', '.');
    }
}
