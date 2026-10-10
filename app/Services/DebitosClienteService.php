<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\Pedido;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quanto cada cliente deve (títulos a receber Pendente/Parcial, a mesma base
 * de Cliente::saldoDevedor()) e de onde vem a dívida: o título de uma venda
 * é aberto nos pedidos cobrados nela. O saldo do título é rateado entre os
 * pedidos na proporção do valor de cada um na venda; o que a venda cobrou
 * fora dos pedidos (itens de balcão, taxa, frete, desconto) vira uma linha de
 * ajuste. As linhas de um título sempre somam o saldo dele.
 *
 * @phpstan-type PedidoDoTitulo array{pedido: Pedido, itens: Collection<int, ItensPedido>, valor: float, em_aberto: float}
 * @phpstan-type AjusteDoTitulo array{descricao: string, valor: float, em_aberto: float}
 * @phpstan-type Titulo array{lancamento: Lancamento, venda: ?Venda, valor: float, recebido: float, restante: float, vencido: bool, pedidos: Collection<int, PedidoDoTitulo>, ajuste: ?AjusteDoTitulo}
 * @phpstan-type DebitoDoCliente array{cliente: ?Cliente, total: float, vencido: float, titulos: Collection<int, Titulo>}
 */
class DebitosClienteService
{
    /** @var Collection<int, DebitoDoCliente>|null */
    private ?Collection $porCliente = null;

    /**
     * somente_vendas deixa de fora os títulos lançados à mão no Financeiro
     * (aba Pendentes do PDV, que só cobra vendas).
     *
     * @param  array{cliente_id?: int|string|null, busca?: string|null, somente_vencidos?: bool|string|null, somente_vendas?: bool}  $filtros
     */
    public function __construct(private array $filtros = []) {}

    /**
     * Clientes com saldo em aberto, do maior devedor para o menor.
     *
     * @return Collection<int, DebitoDoCliente>
     */
    public function porCliente(): Collection
    {
        if ($this->porCliente !== null) {
            return $this->porCliente;
        }

        $lancamentos = $this->lancamentos();
        $itensPorVenda = $this->itensDePedidoPorVenda($lancamentos->pluck('venda_id')->filter()->unique()->values()->all());

        return $this->porCliente = $lancamentos
            ->map(fn (Lancamento $lancamento): array => $this->detalharTitulo($lancamento, $itensPorVenda->get($lancamento->venda_id, collect())))
            ->groupBy(fn (array $titulo): int => (int) $titulo['lancamento']->cliente_id)
            ->map(fn (Collection $titulos): array => [
                'cliente' => $titulos->first()['lancamento']->cliente,
                'total' => round($titulos->sum('restante'), 2),
                'vencido' => round($titulos->where('vencido', true)->sum('restante'), 2),
                'titulos' => $titulos->values(),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /**
     * @return array{clientes: int, titulos: int, total: float, vencido: float}
     */
    public function totais(): array
    {
        $porCliente = $this->porCliente();

        return [
            'clientes' => $porCliente->count(),
            'titulos' => $porCliente->sum(fn (array $debito): int => $debito['titulos']->count()),
            'total' => round($porCliente->sum('total'), 2),
            'vencido' => round($porCliente->sum('vencido'), 2),
        ];
    }

    /**
     * Títulos de um cliente agrupados pelo mês da compra (data da venda; título
     * lançado à mão usa a data em que foi criado), do mais antigo ao mais novo.
     *
     * @param  Collection<int, Titulo>  $titulos
     * @return Collection<string, array{mes: string, total: float, titulos: Collection<int, Titulo>}>
     */
    public static function agruparPorMes(Collection $titulos): Collection
    {
        $dataDoTitulo = fn (array $titulo) => $titulo['venda']?->created_at ?? $titulo['lancamento']->created_at;

        return $titulos
            ->sortBy(fn (array $titulo): string => (string) $dataDoTitulo($titulo)?->format('Y-m-d H:i:s'))
            ->groupBy(fn (array $titulo): string => (string) $dataDoTitulo($titulo)?->format('Y-m'))
            ->map(fn (Collection $doMes): array => [
                'mes' => ucfirst((string) $dataDoTitulo($doMes->first())?->translatedFormat('F \\d\\e Y')) ?: 'Sem data',
                'total' => round($doMes->sum('restante'), 2),
                'titulos' => $doMes->values(),
            ]);
    }

    /**
     * @return Collection<int, Lancamento>
     */
    private function lancamentos(): Collection
    {
        $busca = trim((string) ($this->filtros['busca'] ?? ''));

        return Lancamento::receber()
            ->pendentes()
            ->when($this->filtros['somente_vendas'] ?? false, fn (Builder $query) => $query->whereNotNull('venda_id'))
            ->when($this->filtros['cliente_id'] ?? null, fn (Builder $query, $clienteId) => $query->where('cliente_id', $clienteId))
            ->when(filter_var($this->filtros['somente_vencidos'] ?? false, FILTER_VALIDATE_BOOLEAN), fn (Builder $query) => $query->whereDate('vencimento', '<', now()->toDateString()))
            ->when($busca !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($busca): void {
                $query->whereHas('cliente', fn (Builder $cliente) => $cliente->where('cliente_nome', 'like', "%{$busca}%"));

                if (ctype_digit($busca)) {
                    $query->orWhere('venda_id', (int) $busca)
                        ->orWhereIn('venda_id', ItensPedido::select('item_pedido_venda_id')->where('item_pedido_pedido_id', (int) $busca))
                        ->orWhereIn('venda_id', Pedido::select('pedido_venda_id')->whereKey((int) $busca));
                }
            }))
            ->with(['cliente', 'venda.pedidos.sessaoMesa.mesa'])
            ->withSum('pagamentos', 'valor')
            ->orderBy('vencimento')
            ->orderBy('id')
            ->get();
    }

    /**
     * Itens de pedido cobrados em cada venda, pelo vínculo item a item
     * (item_pedido_venda_id) — uma venda pode levar só parte de um pedido.
     *
     * @param  array<int, int>  $vendaIds
     * @return Collection<int, Collection<int, ItensPedido>>
     */
    private function itensDePedidoPorVenda(array $vendaIds): Collection
    {
        if ($vendaIds === []) {
            return collect();
        }

        return ItensPedido::whereIn('item_pedido_venda_id', $vendaIds)
            ->where('item_pedido_status', 'INSERIDO')
            ->with(['produto.categoria', 'pedido.sessaoMesa.mesa'])
            ->orderBy('item_pedido_pedido_id')
            ->orderBy('id')
            ->get()
            ->groupBy('item_pedido_venda_id');
    }

    /**
     * @param  Collection<int, ItensPedido>  $itensDaVenda
     * @return Titulo
     */
    private function detalharTitulo(Lancamento $lancamento, Collection $itensDaVenda): array
    {
        $venda = $lancamento->venda_id !== null ? $lancamento->venda : null;

        $pedidos = $itensDaVenda
            ->groupBy('item_pedido_pedido_id')
            ->map(fn (Collection $itens): array => [
                'pedido' => $itens->first()->pedido,
                'itens' => $itens->values(),
                'valor' => round($itens->sum(fn (ItensPedido $item): float => (float) $item->item_pedido_valor), 2),
            ]);

        // Vendas antigas ligam o pedido inteiro por pedido_venda_id, sem marcar os itens.
        foreach ($venda?->pedidos ?? [] as $pedido) {
            if (! $pedidos->has($pedido->id)) {
                $pedidos->put($pedido->id, ['pedido' => $pedido, 'itens' => collect(), 'valor' => round((float) $pedido->pedido_valor_total, 2)]);
            }
        }

        $partes = $pedidos->map(fn (array $pedido): float => $pedido['valor'])->all();
        $ajuste = $venda
            ? round((float) $venda->venda_valor_total - array_sum($partes), 2)
            : (float) $lancamento->valor;

        if (abs($ajuste) >= 0.01 || $partes === []) {
            $partes['ajuste'] = $ajuste;
        }

        $restante = $lancamento->valor_restante;
        $emAberto = $this->ratear($restante, $partes);

        return [
            'lancamento' => $lancamento,
            'venda' => $venda,
            'valor' => (float) $lancamento->valor,
            'recebido' => $lancamento->valor_pago,
            'restante' => $restante,
            'vencido' => $lancamento->esta_vencido,
            'pedidos' => $pedidos
                ->map(fn (array $pedido, int $pedidoId): array => $pedido + ['em_aberto' => $emAberto[$pedidoId]])
                ->sortKeys()
                ->values(),
            'ajuste' => array_key_exists('ajuste', $partes) ? [
                'descricao' => $venda ? $this->descreverAjuste($venda, $ajuste) : ($lancamento->descricao ?: 'Título a receber'),
                'valor' => $ajuste,
                'em_aberto' => $emAberto['ajuste'],
            ] : null,
        ];
    }

    /**
     * Reparte o saldo (em centavos) na proporção de cada parte; a sobra do
     * arredondamento fica com a maior parte, para a soma bater exata.
     *
     * @param  array<int|string, float>  $partes
     * @return array<int|string, float>
     */
    private function ratear(float $restante, array $partes): array
    {
        $centavos = (int) round($restante * 100);
        $base = array_sum($partes);
        $absolutos = array_map('abs', $partes);
        $maior = array_search(max($absolutos), $absolutos, true);

        $alocado = array_map(
            fn (float $parte): int => abs($base) < 0.01 ? 0 : (int) round($centavos * $parte / $base),
            $partes,
        );
        $alocado[$maior] += $centavos - array_sum($alocado);

        return array_map(fn (int $valor): float => $valor / 100, $alocado);
    }

    /**
     * O que a venda cobrou além dos itens de pedido (venda_valor_total = itens
     * + frete + taxa), ex.: "Taxa de serviço, frete e itens de balcão". O que
     * sobra fora de taxa e frete são itens lançados à mão (positivo) ou
     * desconto dado no caixa sobre linhas de pedido (negativo).
     */
    private function descreverAjuste(Venda $venda, float $ajuste): string
    {
        $taxa = (float) $venda->venda_valor_taxa_servico;
        $frete = (float) $venda->venda_valor_frete;
        $resto = round($ajuste - $taxa - $frete, 2);

        $partes = array_keys(array_filter([
            'taxa de serviço' => $taxa >= 0.01,
            'frete' => $frete >= 0.01,
            'itens de balcão' => $resto >= 0.01,
            'desconto no caixa' => $resto <= -0.01,
        ]));

        if ($partes === []) {
            return 'Ajuste da venda';
        }

        $ultima = array_pop($partes);

        return ucfirst($partes === [] ? $ultima : implode(', ', $partes).' e '.$ultima);
    }
}
