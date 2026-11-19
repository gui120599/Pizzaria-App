<?php

namespace App\Services\PromocaoCombo;

use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\PedidoComboAplicacao;
use App\Models\PedidoComboItem;
use App\Models\Produto;
use App\Models\PromocaoCombo;
use App\Models\PromocaoComboParticipante;
use Illuminate\Support\Collection;

/**
 * Aplica as promoções combo nas linhas de um pedido.
 *
 * O desconto vai para `item_pedido_desconto_combo` e `item_pedido_valor` já o
 * desconta (mesmo padrão do cupom), então venda, NFC-e, caixa e Stone herdam
 * o valor certo sem código extra. O combo vem antes do cupom: o cupom incide
 * sobre o líquido.
 *
 * Reaplicado do zero a cada mudança de item (TotaisPedidoService::recalcular),
 * com duas exceções que preservam o que já foi combinado com o cliente:
 *  - linha já vendida (`item_pedido_venda_id`) fica congelada, e a aplicação
 *    que a contém também;
 *  - promoção que já está no pedido continua valendo para ele mesmo depois
 *    de sair da vigência (o pedido editado às 20h30 não perde o combo das 19h55).
 */
class PromocaoComboService
{
    public function __construct(private readonly PromocaoComboMotor $motor) {}

    /**
     * Chamar dentro da transação que altera os itens, com o pedido travado.
     *
     * @param  int|null  $opcaoEntregaId  opção que está sendo gravada agora (envio do rascunho); null = a do pedido
     */
    public function reaplicar(Pedido $pedido, ?int $opcaoEntregaId = null): void
    {
        $vigentes = PromocaoCombo::query()->vigente()->get();
        $aplicacoes = PedidoComboAplicacao::where('pca_pedido_id', $pedido->id)->with('itens')->get();

        if ($vigentes->isEmpty() && $aplicacoes->isEmpty()) {
            return;
        }

        /** @var Collection<int, ItensPedido> $itens */
        $itens = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
            ->where('item_pedido_status', 'INSERIDO')
            ->withCount(['itensOferta' => fn ($q) => $q->where('item_pedido_status', 'INSERIDO')])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $vendidos = $itens->whereNotNull('item_pedido_venda_id')->pluck('id')->all();
        $congeladas = $aplicacoes->filter(
            fn (PedidoComboAplicacao $aplicacao): bool => $aplicacao->itens->contains(fn (PedidoComboItem $parte): bool => in_array($parte->pci_item_pedido_id, $vendidos, true)),
        );

        $promocoes = $vigentes->keyBy('id');
        $jaNoPedido = $aplicacoes->pluck('pca_promocao_combo_id')->filter()->unique()->diff($promocoes->keys());

        if ($jaNoPedido->isNotEmpty()) {
            PromocaoCombo::withTrashed()->whereKey($jaNoPedido)->get()->each(fn (PromocaoCombo $p) => $promocoes->put($p->id, $p));
        }

        $opcao = $opcaoEntregaId ?? $pedido->pedido_opcaoentrega_id;
        $ehMesa = $pedido->pedido_sessao_mesa_id !== null;
        $promocoes = $promocoes->filter(fn (PromocaoCombo $p): bool => $p->entregaPermitida($opcao, $ehMesa));

        $unidadesCongeladas = [];
        $descontoCongelado = [];

        foreach ($congeladas as $aplicacao) {
            foreach ($aplicacao->itens as $parte) {
                $unidadesCongeladas[$parte->pci_item_pedido_id] = ($unidadesCongeladas[$parte->pci_item_pedido_id] ?? 0) + (int) round((float) $parte->pci_unidades);
                $descontoCongelado[$parte->pci_item_pedido_id] = ($descontoCongelado[$parte->pci_item_pedido_id] ?? 0) + self::centavos($parte->pci_desconto);
            }
        }

        $livres = $itens->whereNull('item_pedido_venda_id')->mapWithKeys(fn (ItensPedido $item): array => [(string) $item->id => $item])->all();

        $plano = $this->planejar(
            $livres,
            $promocoes->values(),
            jaAplicadas: $congeladas->countBy('pca_promocao_combo_id')->all(),
            unidadesOcupadas: array_combine(array_map('strval', array_keys($unidadesCongeladas)), $unidadesCongeladas),
        );

        PedidoComboAplicacao::whereKey($aplicacoes->diff($congeladas)->modelKeys())->delete();
        $this->gravarAplicacoes($pedido, $plano);

        $novos = $plano->descontoPorLinha();

        foreach ($livres as $chave => $item) {
            $this->gravarDescontoNaLinha($item, (($novos[$chave] ?? 0) + ($descontoCongelado[$item->id] ?? 0)) / 100);
        }
    }

    /**
     * Plano para um conjunto de linhas, gravadas ou de prévia (sem banco).
     *
     * @param  array<string, ItensPedido>  $linhas  chave => linha; a ordem de inclusão segue a ordem do array
     * @param  Collection<int, PromocaoCombo>|null  $promocoes  null = as vigentes, filtradas pela opção de entrega
     * @param  array<int, int>  $jaAplicadas  promoção => aplicações congeladas (contam no limite por pedido)
     * @param  array<string, int>  $unidadesOcupadas  chave => unidades já presas em aplicação congelada
     */
    public function planejar(
        array $linhas,
        ?Collection $promocoes = null,
        ?int $opcaoEntregaId = null,
        bool $ehMesa = false,
        array $jaAplicadas = [],
        array $unidadesOcupadas = [],
    ): PlanoCombo {
        $promocoes ??= PromocaoCombo::query()->vigente()->get()
            ->filter(fn (PromocaoCombo $p): bool => $p->entregaPermitida($opcaoEntregaId, $ehMesa));

        if ($promocoes->isEmpty() || $linhas === []) {
            return new PlanoCombo;
        }

        $regras = $this->regras($promocoes);
        $categorias = $this->categoriasDosProdutos($linhas);
        $entrada = [];
        $ordem = 0;

        foreach ($linhas as $chave => $item) {
            $ordem++;
            $linha = $this->linha((string) $chave, $ordem, $item, $categorias, $unidadesOcupadas[(string) $chave] ?? 0);

            if ($linha !== null) {
                $entrada[] = $linha;
            }
        }

        return $this->motor->planejar($entrada, $regras, $jaAplicadas);
    }

    /**
     * Promoções vigentes para o cardápio, com os produtos exibidos que
     * participam de cada uma (base do convite "adicione mais 1 e leve 2 por
     * R$ X"). Só exibição: quem decide o preço é planejar().
     *
     * @param  array<int, array{categoria_id: ?int, relampago: bool}>  $produtos  produto exibido => categoria e se está em relâmpago
     * @return list<array{promocaoId: int, nome: string, descricao: ?string, quantidade: int, preco: float, sugerir: bool, produtos: list<int>}>
     */
    public function paraCardapio(array $produtos, bool $mesa = false): array
    {
        $promocoes = PromocaoCombo::query()->vigente()->orderByDesc('promocombo_prioridade')->orderBy('id')->get()
            ->filter(fn (PromocaoCombo $p): bool => $p->entregaPermitida(null, $mesa));

        if ($promocoes->isEmpty() || $produtos === []) {
            return [];
        }

        $descricoes = $promocoes->pluck('promocombo_descricao', 'id');

        return collect($this->regras($promocoes))
            ->map(function (RegraCombo $regra) use ($produtos, $descricoes): array {
                $participantes = [];

                foreach ($produtos as $id => $produto) {
                    $linha = new LinhaCombo((string) $id, 0, 1, 0, [['produto_id' => (int) $id, 'categoria_id' => $produto['categoria_id']]], $produto['relampago']);

                    if ($regra->aceita($linha)) {
                        $participantes[] = (int) $id;
                    }
                }

                return [
                    'promocaoId' => $regra->promocaoId,
                    'nome' => $regra->nome,
                    'descricao' => $descricoes[$regra->promocaoId] ?? null,
                    'quantidade' => $regra->quantidade,
                    'preco' => $regra->precoCentavos / 100,
                    'sugerir' => $regra->sugerir,
                    'produtos' => $participantes,
                ];
            })
            ->filter(fn (array $promocao): bool => $promocao['produtos'] !== [])
            ->values()
            ->all();
    }

    /**
     * Valor da linha sem combo e sem cupom: o que `calcularLinha()` daria.
     * Cortesia não cobra nada.
     */
    public static function valorSemDescontosExtras(ItensPedido $item): float
    {
        if ($item->item_pedido_cortesia) {
            return 0.0;
        }

        return round(
            (float) $item->item_pedido_valor_unitario * (float) $item->item_pedido_quantidade
                - (float) $item->item_pedido_desconto
                + (float) $item->item_pedido_valor_adicionais,
            2,
        );
    }

    /**
     * @param  array<int, int>  $categorias  produto => categoria
     */
    private function linha(string $chave, int $ordem, ItensPedido $item, array $categorias, int $ocupadas): ?LinhaCombo
    {
        $quantidade = (float) $item->item_pedido_quantidade;
        $unidades = (int) floor($quantidade + 0.0001) - $ocupadas;

        if ($unidades <= 0
            || ($item->item_pedido_status ?? 'INSERIDO') !== 'INSERIDO'
            || $item->item_pedido_cortesia
            || $item->item_pedido_origem_id !== null) {
            return null;
        }

        $produtoIds = $item->ehMultiSabor()
            ? array_map(fn (array $sabor): int => (int) $sabor['produto_id'], $item->sabores())
            : [(int) $item->item_pedido_produto_id];

        $base = (float) $item->item_pedido_valor_unitario * $quantidade - (float) $item->item_pedido_desconto;

        return new LinhaCombo(
            chave: $chave,
            ordem: $ordem,
            unidades: $unidades,
            baseUnitariaCentavos: (int) round($base * 100 / $quantidade),
            produtos: array_map(fn (int $id): array => ['produto_id' => $id, 'categoria_id' => $categorias[$id] ?? null], $produtoIds),
            relampago: $item->item_pedido_promocao_id !== null,
            promoAdicional: $item->item_pedido_promocao_adicional_regra_id !== null || (int) ($item->itens_oferta_count ?? 0) > 0,
        );
    }

    /**
     * @param  array<string, ItensPedido>  $linhas
     * @return array<int, int>
     */
    private function categoriasDosProdutos(array $linhas): array
    {
        $ids = [];

        foreach ($linhas as $item) {
            $ids[] = (int) $item->item_pedido_produto_id;

            foreach ($item->sabores() as $sabor) {
                $ids[] = (int) $sabor['produto_id'];
            }
        }

        return Produto::withTrashed()->whereKey(array_unique($ids))->pluck('produto_categoria_id', 'id')
            ->map(fn ($categoria): int => (int) $categoria)
            ->all();
    }

    /**
     * @param  Collection<int, PromocaoCombo>  $promocoes
     * @return list<RegraCombo>
     */
    private function regras(Collection $promocoes): array
    {
        $participantes = PromocaoComboParticipante::whereIn('pcp_promocao_combo_id', $promocoes->modelKeys())->get()
            ->groupBy('pcp_promocao_combo_id');

        return $promocoes->map(function (PromocaoCombo $promocao) use ($participantes): RegraCombo {
            $lista = $participantes->get($promocao->id, collect());
            [$excluidos, $incluidos] = $lista->partition(fn (PromocaoComboParticipante $p): bool => $p->pcp_excluir);

            return new RegraCombo(
                promocaoId: $promocao->id,
                nome: $promocao->promocombo_nome,
                quantidade: (int) $promocao->promocombo_quantidade,
                precoCentavos: self::centavos($promocao->promocombo_preco),
                prioridade: (int) $promocao->promocombo_prioridade,
                limitePorPedido: $promocao->promocombo_limite_por_pedido,
                aplicaFracionado: (bool) $promocao->promocombo_aplica_fracionado,
                cumulaRelampago: (bool) $promocao->promocombo_cumula_relampago,
                cumulaPromoAdicional: (bool) $promocao->promocombo_cumula_promo_adicional,
                sugerir: (bool) $promocao->promocombo_sugerir,
                categoriasIncluidas: Categoria::idsComSubcategorias($incluidos->pluck('pcp_categoria_id')->filter()->all()),
                produtosIncluidos: $incluidos->pluck('pcp_produto_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
                categoriasExcluidas: Categoria::idsComSubcategorias($excluidos->pluck('pcp_categoria_id')->filter()->all()),
                produtosExcluidos: $excluidos->pluck('pcp_produto_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
            );
        })->values()->all();
    }

    private function gravarAplicacoes(Pedido $pedido, PlanoCombo $plano): void
    {
        foreach ($plano->aplicacoes as $aplicacao) {
            $registro = PedidoComboAplicacao::create([
                'pca_pedido_id' => $pedido->id,
                'pca_promocao_combo_id' => $aplicacao->promocaoId,
                'pca_chave' => $aplicacao->chave,
                'pca_nome' => $aplicacao->nome,
                'pca_quantidade' => $aplicacao->quantidade,
                'pca_preco' => $aplicacao->precoCentavos / 100,
                'pca_valor_cheio' => $aplicacao->valorCheioCentavos() / 100,
                'pca_desconto' => $aplicacao->descontoCentavos() / 100,
            ]);

            foreach ($aplicacao->partes as $chave => $parte) {
                $registro->itens()->create([
                    'pci_item_pedido_id' => (int) $chave,
                    'pci_unidades' => $parte['unidades'],
                    'pci_valor_cheio' => $parte['cheio'] / 100,
                    'pci_desconto' => $parte['desconto'] / 100,
                ]);
            }
        }
    }

    /**
     * Grava a parte do combo e o valor líquido. Linha sem combo antes e
     * depois fica intocada; com combo, o valor é refeito a partir do preço da
     * linha (outros caminhos podem ter regravado o valor sem o combo).
     */
    private function gravarDescontoNaLinha(ItensPedido $item, float $descontoCombo): void
    {
        $atual = round((float) $item->item_pedido_desconto_combo, 2);
        $descontoCombo = round($descontoCombo, 2);

        if ($atual <= 0.0 && $descontoCombo <= 0.0) {
            return;
        }

        $base = self::valorSemDescontosExtras($item);
        $descontoCombo = min($descontoCombo, $base);
        $descontoCupom = min(round((float) $item->item_pedido_desconto_cupom, 2), round($base - $descontoCombo, 2));
        $valor = round($base - $descontoCombo - $descontoCupom, 2);

        if (abs($atual - $descontoCombo) < 0.001
            && abs((float) $item->item_pedido_valor - $valor) < 0.001
            && abs((float) $item->item_pedido_desconto_cupom - $descontoCupom) < 0.001) {
            return;
        }

        $item->update([
            'item_pedido_desconto_combo' => $descontoCombo,
            'item_pedido_desconto_cupom' => $descontoCupom,
            'item_pedido_valor' => $valor,
        ]);
    }

    private static function centavos(mixed $valor): int
    {
        return (int) round((float) $valor * 100);
    }
}
