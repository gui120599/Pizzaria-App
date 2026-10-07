<?php

namespace App\Services;

use App\Enums\CanalLancamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Exceptions\ComboSaboresInvalidoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\ItemIndisponivelException;
use App\Exceptions\PerguntaNaoRespondidaException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\AdicionaisItemPedido;
use App\Models\ItensPedido;
use App\Models\Pergunta;
use App\Models\PerguntaOpcao;
use App\Models\Produto;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoRelampago;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lançamento de item em pedido, o mesmo para todos os canais (seletor do
 * garçom/balcão, checkout do cardápio e a mesa por QR): resolve o preço no
 * servidor, confere se o canal vende o produto, valida estoque e limites de
 * promoção e grava item, adicionais, consumo da promoção e a linha da oferta.
 *
 * Quem chama decide a ordem das validações e como mostrar o erro: o checkout
 * recusa o carrinho inteiro, o seletor recusa só o item da vez.
 */
class LancamentoItemPedidoService
{
    /** Categoria (e o pai, para a herança de sabores) e adicionais vinculados. */
    private const RELACOES_PRODUTO = [
        'categoria.quantidadesSabores',
        'categoria.pai.quantidadesSabores',
        'ap_produto_id.adicional',
    ];

    public function __construct(
        private PrecificadorService $precificador,
        private EstoqueService $estoque,
        private PromocaoRelampagoService $relampago,
        private PromocaoAdicionalService $promocoesAdicionais,
    ) {}

    /**
     * @param  bool  $comPromocoes  false = preço sem relâmpago nem promoção adicional, e sem oferta
     *                              (prévia sem pedido gravado, onde não há como registrar o consumo)
     *
     * @throws ItemIndisponivelException|ComboSaboresInvalidoException|PerguntaNaoRespondidaException
     */
    public function precificar(
        ItemSolicitado $item,
        CanalLancamentoEnum $canal,
        ?int $opcaoPagamentoId = null,
        bool $comPromocoes = true,
    ): LinhaPrecificada {
        $ids = $item->ehMultiSabor() ? $item->saboresIds : [$item->produtoId];
        $produtos = $this->produtosNaOrdem($ids, $canal);

        $adicionais = $this->adicionaisPermitidos($produtos, $item->adicionaisIds);

        // Pizza de sabores responde as perguntas da categoria; item comum, as
        // da categoria e as do próprio produto.
        $perguntas = $item->ehMultiSabor()
            ? ($produtos[0]->categoria?->perguntasAplicaveis() ?? collect())
            : $produtos[0]->perguntasAplicaveis();
        $respostas = $this->respostasValidadas($perguntas, $item->respostas);

        $valorAdicionais = round(
            $this->valorDosAdicionais($adicionais, $item->quantidade)
                + $this->valorDasRespostas($respostas, $item->quantidade),
            2,
        );

        $comum = [
            'item_pedido_cliente_id' => $item->clienteId,
            'item_pedido_respostas' => $respostas ?: null,
            'item_pedido_valor_adicionais' => $valorAdicionais,
            'item_pedido_observacao' => filled(trim((string) $item->observacao)) ? trim((string) $item->observacao) : null,
            'item_pedido_status' => 'INSERIDO',
        ];

        if ($item->ehMultiSabor()) {
            $combo = $this->precificador->precificarCombo(
                $produtos,
                (int) $item->quantidade,
                $this->precificador->opcaoDoCombo($produtos),
                $opcaoPagamentoId,
                $comPromocoes,
            );

            // Pizza de sabores não recebe oferta de promoção adicional.
            return new LinhaPrecificada([
                'item_pedido_produto_id' => $combo['produto_id'],
                'item_pedido_promocao_id' => $combo['promocao_id'],
                'item_pedido_promocao_adicional_regra_id' => $combo['promocao_adicional_regra_id'],
                'item_pedido_quantidade' => $combo['quantidade'],
                'item_pedido_valor_unitario' => $combo['valor_unitario'],
                'item_pedido_valor' => round($combo['valor'] + $valorAdicionais, 2),
                'item_pedido_desconto' => $combo['desconto'],
                'item_pedido_desconto_unitario' => $combo['desconto_unitario'],
                'item_pedido_sabores' => $combo['sabores'],
            ] + $comum, $adicionais, $this->produtoDaLinha($produtos, $combo['produto_id']));
        }

        $produto = $produtos[0];
        $preco = $this->precificador->resolver(
            $produto,
            considerarRelampago: $comPromocoes,
            considerarPromoAdicional: $comPromocoes,
            opcaoPagamentoId: $opcaoPagamentoId,
        );
        $linha = ItensPedido::calcularLinha($item->quantidade, $preco->valorUnitario, $preco->descontoUnitario, $valorAdicionais);
        $oferta = $comPromocoes ? $this->ofertaEscolhida($produto, $item->ofertaProdutoId, $canal, $opcaoPagamentoId) : null;

        return new LinhaPrecificada([
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_promocao_id' => $preco->promocaoId,
            'item_pedido_promocao_adicional_regra_id' => $preco->promocaoAdicionalRegraId,
            'item_pedido_quantidade' => $item->quantidade,
            'item_pedido_valor_unitario' => $linha['valor_unitario'],
            'item_pedido_valor' => $linha['valor'],
            'item_pedido_desconto' => $linha['desconto'],
            'item_pedido_desconto_unitario' => $preco->descontoUnitario,
            'item_pedido_sabores' => null,
        ] + $comum, $adicionais, $produto, $oferta?->regra, $oferta);
    }

    /**
     * Estoque das linhas somado por produto (o mesmo produto pode vir em
     * várias linhas e em sabores de pizzas diferentes).
     *
     * @param  list<LinhaPrecificada>  $linhas
     * @return list<string> avisos (modo AVISAR)
     *
     * @throws EstoqueInsuficienteException algum produto em modo BLOQUEAR sem saldo
     */
    public function validarEstoque(array $linhas): array
    {
        return $this->validarConsumo(array_map(fn (LinhaPrecificada $linha) => $linha->previa(), $linhas));
    }

    /**
     * Mesma checagem de validarEstoque() para itens já gravados — ex.: o
     * pedido do QR que esperou a aprovação do garçom.
     *
     * @param  iterable<ItensPedido>  $itens
     * @return list<string> avisos (modo AVISAR)
     *
     * @throws EstoqueInsuficienteException
     */
    public function validarEstoqueDosItens(iterable $itens): array
    {
        return $this->validarConsumo(collect($itens)->all());
    }

    /**
     * @param  list<ItensPedido>  $itens
     * @return list<string>
     *
     * @throws EstoqueInsuficienteException
     */
    private function validarConsumo(array $itens): array
    {
        $consumo = [];

        foreach ($itens as $item) {
            foreach ($item->consumosPorProduto() as $produtoId => $quantidade) {
                $consumo[$produtoId] = ($consumo[$produtoId] ?? 0) + $quantidade;
            }
        }

        $produtos = Produto::whereIn('id', array_keys($consumo))->get()->keyBy('id');
        $bloqueios = [];
        $avisos = [];

        foreach ($consumo as $produtoId => $quantidade) {
            if (! $produto = $produtos->get($produtoId)) {
                continue;
            }

            $resultado = $this->estoque->checarDisponibilidade($produto, (float) $quantidade);
            array_push($bloqueios, ...$resultado['bloqueios']);
            array_push($avisos, ...$resultado['avisos']);
        }

        if ($bloqueios !== []) {
            throw new EstoqueInsuficienteException(implode(' | ', $bloqueios));
        }

        return $avisos;
    }

    /**
     * Limite por pedido da promoção relâmpago, somando as linhas novas ao que
     * o pedido já consumiu.
     *
     * @param  list<LinhaPrecificada>  $linhas
     *
     * @throws PromocaoIndisponivelException
     */
    public function validarLimitesPromocao(array $linhas, ?int $pedidoId = null): void
    {
        $porPromocao = collect($linhas)
            ->filter(fn (LinhaPrecificada $linha) => $linha->atributos['item_pedido_promocao_id'] !== null)
            ->groupBy(fn (LinhaPrecificada $linha) => $linha->atributos['item_pedido_promocao_id']);

        foreach ($porPromocao as $promocaoId => $doGrupo) {
            if (! $promocao = PromocaoRelampago::find($promocaoId)) {
                continue;
            }

            $jaNoPedido = $pedidoId ? $this->relampago->quantidadeNoPedido($promocao, $pedidoId) : 0.0;

            $this->relampago->validarLimitePorPedido(
                $promocao,
                $jaNoPedido + (float) $doGrupo->sum(fn (LinhaPrecificada $linha) => $linha->quantidade()),
            );
        }
    }

    /**
     * Limite por pedido das ofertas de promoção adicional aceitas, contando
     * quantas vezes cada regra aparece (não importa qual oferta foi escolhida).
     *
     * @param  list<LinhaPrecificada>  $linhas
     *
     * @throws PromocaoIndisponivelException
     */
    public function validarLimitesOferta(array $linhas, ?int $pedidoId = null): void
    {
        $porRegra = collect($linhas)
            ->filter(fn (LinhaPrecificada $linha) => $linha->regraOferta !== null)
            ->groupBy(fn (LinhaPrecificada $linha) => $linha->regraOferta->id);

        foreach ($porRegra as $doGrupo) {
            $regra = $doGrupo->first()->regraOferta;
            $jaNoPedido = $pedidoId ? $this->promocoesAdicionais->aceitesNoPedido($regra, $pedidoId) : 0;

            $this->promocoesAdicionais->validarLimitePorPedido($regra, $jaNoPedido + $doGrupo->count());
        }
    }

    /**
     * Grava a linha (item, adicionais e consumo da promoção relâmpago). A
     * oferta vai à parte, em gravarOferta().
     *
     * @throws PromocaoIndisponivelException saldo da promoção esgotou
     */
    public function gravar(int $pedidoId, LinhaPrecificada $linha): ItensPedido
    {
        return DB::transaction(function () use ($pedidoId, $linha) {
            $item = ItensPedido::create(['item_pedido_pedido_id' => $pedidoId] + $linha->atributos);

            $this->gravarAdicionais($item, $linha->adicionais);

            if ($item->item_pedido_promocao_id) {
                $this->relampago->consumir($item);
            }

            return $item;
        });
    }

    /**
     * Grava a linha da oferta aceita para o item-gatilho e consome o saldo da
     * regra. Revalida o limite por pedido aqui dentro: entre a escolha e a
     * gravação, outra linha pode ter usado o último aceite.
     *
     * @throws PromocaoIndisponivelException
     */
    public function gravarOferta(int $pedidoId, ItensPedido $gatilho, LinhaPrecificada $linha): ?ItensPedido
    {
        if (! $linha->oferta || ! $linha->regraOferta) {
            return null;
        }

        return DB::transaction(function () use ($pedidoId, $gatilho, $linha) {
            $regra = $linha->regraOferta;
            $oferta = $linha->oferta;

            $this->promocoesAdicionais->validarLimitePorPedido(
                $regra,
                $this->promocoesAdicionais->aceitesNoPedido($regra, $pedidoId) + 1,
            );

            $item = ItensPedido::create([
                'item_pedido_pedido_id' => $pedidoId,
                'item_pedido_produto_id' => $oferta->pao_produto_oferta_id,
                'item_pedido_promocao_adicional_regra_id' => $regra->id,
                'item_pedido_promocao_adicional_oferta_id' => $oferta->id,
                'item_pedido_origem_id' => $gatilho->id,
                'item_pedido_cliente_id' => $gatilho->item_pedido_cliente_id,
                'item_pedido_quantidade' => 1,
                'item_pedido_valor_unitario' => $oferta->pao_valor_adicional,
                'item_pedido_valor' => $oferta->pao_valor_adicional,
                'item_pedido_desconto' => 0,
                'item_pedido_desconto_unitario' => 0,
                'item_pedido_valor_adicionais' => 0,
                'item_pedido_status' => 'INSERIDO',
            ]);

            $this->promocoesAdicionais->consumir($item);

            return $item;
        });
    }

    /**
     * Troca os adicionais de um item já gravado, mantendo o preço congelado
     * da linha. Só aceita adicionais vinculados ao produto (na pizza de
     * sabores, os vinculados a todos os sabores).
     *
     * @param  list<int>  $adicionaisIds
     */
    public function trocarAdicionais(ItensPedido $item, array $adicionaisIds): ItensPedido
    {
        $produtoIds = $item->ehMultiSabor()
            ? array_column($item->item_pedido_sabores, 'produto_id')
            : [$item->item_pedido_produto_id];
        $produtos = Produto::with('ap_produto_id.adicional')->whereIn('id', $produtoIds)->get()->all();
        $adicionais = $this->adicionaisPermitidos($produtos, $adicionaisIds);

        return DB::transaction(function () use ($item, $adicionais) {
            $baseSemAdicionais = round((float) $item->item_pedido_valor - (float) $item->item_pedido_valor_adicionais, 2);
            $valorAdicionais = round(
                $this->valorDosAdicionais($adicionais, (float) $item->item_pedido_quantidade)
                    + $this->valorDasRespostas($item->respostas(), (float) $item->item_pedido_quantidade),
                2,
            );

            $item->update([
                'item_pedido_valor_adicionais' => $valorAdicionais,
                'item_pedido_valor' => round($baseSemAdicionais + $valorAdicionais, 2),
            ]);

            $this->gravarAdicionais($item, $adicionais);

            return $item;
        });
    }

    /**
     * Nova quantidade de um item já gravado, com o preço congelado da linha e
     * os adicionais acompanhando a quantidade.
     */
    public function atualizarQuantidade(ItensPedido $item, float $quantidade): ItensPedido
    {
        return DB::transaction(function () use ($item, $quantidade) {
            $quantidadeAnterior = (float) $item->item_pedido_quantidade;
            $descontoUnitario = $item->item_pedido_desconto_unitario !== null
                ? (float) $item->item_pedido_desconto_unitario
                : ($quantidadeAnterior > 0 ? (float) $item->item_pedido_desconto / $quantidadeAnterior : 0.0);

            $adicionais = $item->adicionaisItemPedido()->get();
            $quantidadeAdicionais = ItensPedido::quantidadeDosAdicionais($quantidade);

            $adicionais->each(fn (AdicionaisItemPedido $adicional) => $adicional->update([
                'aip_quantidade' => $quantidadeAdicionais,
                'aip_valor_total' => round((float) $adicional->aip_valor_unitario * $quantidadeAdicionais, 2),
            ]));

            $linha = ItensPedido::calcularLinha(
                $quantidade,
                (float) $item->item_pedido_valor_unitario,
                $descontoUnitario,
                (float) $adicionais->sum('aip_valor_total') + $this->valorDasRespostas($item->respostas(), $quantidade),
            );

            $item->update([
                'item_pedido_quantidade' => $quantidade,
                'item_pedido_desconto' => $linha['desconto'],
                'item_pedido_valor_adicionais' => $linha['adicionais'],
                'item_pedido_valor' => $linha['valor'],
            ]);

            return $item;
        });
    }

    /**
     * Regrava os adicionais do item: cada um cobrado uma vez por unidade
     * (ver ItensPedido::quantidadeDosAdicionais()).
     *
     * @param  list<array{id: int, valor: float}>  $adicionais  Valor unitário de cada adicional
     */
    public function gravarAdicionais(ItensPedido $item, array $adicionais): void
    {
        $quantidade = ItensPedido::quantidadeDosAdicionais((float) $item->item_pedido_quantidade);

        AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->delete();

        foreach ($adicionais as $adicional) {
            AdicionaisItemPedido::create([
                'aip_item_pedido_id' => $item->id,
                'aip_adicional_id' => $adicional['id'],
                'aip_quantidade' => $quantidade,
                'aip_valor_unitario' => $adicional['valor'],
                'aip_valor_total' => round((float) $adicional['valor'] * $quantidade, 2),
            ]);
        }
    }

    /**
     * Total dos adicionais da linha: soma dos unitários × quantidade cobrada.
     *
     * @param  list<array{valor: float}>  $adicionais
     */
    public function valorDosAdicionais(array $adicionais, float $quantidadeItem): float
    {
        return round(
            array_sum(array_column($adicionais, 'valor')) * ItensPedido::quantidadeDosAdicionais($quantidadeItem),
            2,
        );
    }

    /**
     * Total das respostas da linha: soma dos acréscimos das opções × a mesma
     * quantidade cobrada dos adicionais.
     *
     * @param  list<array{opcoes: list<array{valor: float}>}>  $respostas
     */
    public function valorDasRespostas(array $respostas, float $quantidadeItem): float
    {
        $unitario = collect($respostas)
            ->flatMap(fn (array $resposta) => $resposta['opcoes'])
            ->sum(fn (array $opcao) => (float) $opcao['valor']);

        return round($unitario * ItensPedido::quantidadeDosAdicionais($quantidadeItem), 2);
    }

    /**
     * Confere as respostas contra as perguntas que valem para o item e devolve
     * o snapshot a congelar (só as perguntas respondidas). Opção que não é da
     * pergunta é descartada — e a pergunta obrigatória sem opção válida recusa
     * o item.
     *
     * @param  Collection<int, Pergunta>  $perguntas
     * @param  array<int, list<int>>  $respostas  pergunta_id => opções escolhidas
     * @return list<array{pergunta_id: int, pergunta: string, opcoes: list<array{id: int, nome: string, valor: float}>}>
     *
     * @throws PerguntaNaoRespondidaException
     */
    private function respostasValidadas(Collection $perguntas, array $respostas): array
    {
        $snapshot = [];

        foreach ($perguntas as $pergunta) {
            $escolhidas = array_map('intval', (array) ($respostas[$pergunta->id] ?? []));
            $opcoes = $pergunta->opcoesAtivas
                ->filter(fn (PerguntaOpcao $opcao) => in_array($opcao->id, $escolhidas, true))
                ->values();

            if ($opcoes->count() < $pergunta->pergunta_minimo) {
                throw new PerguntaNaoRespondidaException($pergunta->pergunta_minimo > 1
                    ? "Escolha pelo menos {$pergunta->pergunta_minimo} opções em \"{$pergunta->pergunta_texto}\"."
                    : "Responda \"{$pergunta->pergunta_texto}\".");
            }

            if ($opcoes->count() > $pergunta->pergunta_maximo) {
                throw new PerguntaNaoRespondidaException($pergunta->pergunta_maximo > 1
                    ? "Escolha no máximo {$pergunta->pergunta_maximo} opções em \"{$pergunta->pergunta_texto}\"."
                    : "Escolha só uma opção em \"{$pergunta->pergunta_texto}\".");
            }

            if ($opcoes->isEmpty()) {
                continue;
            }

            $snapshot[] = [
                'pergunta_id' => $pergunta->id,
                'pergunta' => $pergunta->pergunta_texto,
                'opcoes' => $opcoes->map(fn (PerguntaOpcao $opcao) => [
                    'id' => $opcao->id,
                    'nome' => $opcao->pergunta_opcao_nome,
                    'valor' => (float) $opcao->pergunta_opcao_valor,
                ])->all(),
            ];
        }

        return $snapshot;
    }

    /**
     * Se o canal vende o produto. Cardápio (e QR da mesa): o que o cardápio público mostra
     * (produto, estoque listável e categoria — com a mãe, se for filha).
     * Salão: o cardápio do garçom.
     */
    public function vendeNoCanal(Produto $produto, CanalLancamentoEnum $canal): bool
    {
        return match ($canal) {
            CanalLancamentoEnum::CARDAPIO, CanalLancamentoEnum::MESA_QR => $produto->visivelNoCardapio()
                && (bool) $produto->categoria?->categoria_cardapio
                && ($produto->categoria->categoria_pai_id === null || (bool) $produto->categoria->pai?->categoria_cardapio),
            CanalLancamentoEnum::SALAO => (bool) $produto->produto_cardapio_garcom
                && in_array($produto->produto_tipo, [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value], true),
        };
    }

    /**
     * @param  list<int>  $ids
     * @return list<Produto>
     *
     * @throws ItemIndisponivelException
     */
    private function produtosNaOrdem(array $ids, CanalLancamentoEnum $canal): array
    {
        $carregados = Produto::with(self::RELACOES_PRODUTO)->whereIn('id', $ids)->get()->keyBy('id');
        $produtos = [];

        foreach ($ids as $id) {
            $produto = $carregados->get($id);

            if (! $produto) {
                throw new ItemIndisponivelException('Um dos produtos do carrinho não está mais disponível.');
            }

            if (! $this->vendeNoCanal($produto, $canal)) {
                throw new ItemIndisponivelException("{$produto->produto_descricao} não está disponível no momento.");
            }

            $produtos[] = $produto;
        }

        return $produtos;
    }

    /**
     * Adicionais escolhidos que estão vinculados a todos os produtos da linha,
     * com o valor do cadastro (nunca o enviado pela tela).
     *
     * @param  list<Produto>  $produtos
     * @param  list<int>  $adicionaisIds
     * @return list<array{id: int, nome: string, valor: float}>
     */
    private function adicionaisPermitidos(array $produtos, array $adicionaisIds): array
    {
        if ($adicionaisIds === [] || $produtos === []) {
            return [];
        }

        /** @var Collection<int, array{id: int, nome: string, valor: float}> $comuns */
        $comuns = collect($produtos)
            ->map(fn (Produto $produto) => $produto->ap_produto_id
                ->filter(fn ($vinculo) => $vinculo->adicional !== null)
                ->mapWithKeys(fn ($vinculo) => [$vinculo->adicional->id => [
                    'id' => $vinculo->adicional->id,
                    'nome' => $vinculo->adicional->adicional_nome,
                    'valor' => (float) $vinculo->adicional->adicional_valor,
                ]]))
            ->reduce(fn (?Collection $acumulado, Collection $doProduto) => $acumulado === null ? $doProduto : $acumulado->intersectByKeys($doProduto));

        return collect(array_unique($adicionaisIds))
            ->map(fn (int $id) => $comuns->get($id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Oferta de promoção adicional escolhida para o item, se ela ainda é uma
     * das opções vigentes com saldo (e permitida pela forma de pagamento).
     * Nunca confia em id de regra/oferta vindo da tela: só no produto.
     */
    private function ofertaEscolhida(Produto $gatilho, ?int $ofertaProdutoId, CanalLancamentoEnum $canal, ?int $opcaoPagamentoId): ?PromocaoAdicionalOferta
    {
        if ($ofertaProdutoId === null) {
            return null;
        }

        $regra = $this->precificador->regraAdicionalDoProduto($gatilho->id, $opcaoPagamentoId);
        $oferta = $regra?->ofertasDisponiveis()
            ->first(fn (PromocaoAdicionalOferta $oferta) => (int) $oferta->pao_produto_oferta_id === $ofertaProdutoId);

        if (! $oferta || ($canal !== CanalLancamentoEnum::SALAO && ! $oferta->produtoOferta?->visivelNoCardapio())) {
            return null;
        }

        return $oferta->setRelation('regra', $regra);
    }

    /** @param  list<Produto>  $produtos */
    private function produtoDaLinha(array $produtos, int $produtoId): Produto
    {
        return collect($produtos)->firstWhere('id', $produtoId) ?? $produtos[0];
    }
}
