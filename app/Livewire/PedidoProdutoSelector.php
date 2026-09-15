<?php

namespace App\Livewire;

use App\Enums\ProdutoTipoEnum;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\AdicionaisItemPedido;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Produto;
use App\Models\PromocaoAdicionalOferta;
use App\Models\PromocaoAdicionalRegra;
use App\Models\PromocaoRelampago;
use App\Services\EstoqueService;
use App\Services\PrecificadorService;
use App\Services\PromocaoAdicionalService;
use App\Services\PromocaoRelampagoService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class PedidoProdutoSelector extends Component
{
    public ?int $pedidoId = null;

    public array $itensIniciais = [];

    public array $itens = [];

    public string $busca = '';

    public ?int $categoriaId = null;

    // Modal produto simples
    public bool $modalAberta = false;

    public ?int $produtoSelecionadoId = null;

    public ?array $produtoSelecionado = null;

    public float $quantidade = 1;

    public string $observacao = '';

    public array $adicionaisDisponiveis = [];

    public array $adicionaisSelecionados = [];

    // Modal de edição de item existente
    public bool $editModalAberta = false;

    public ?string $editItemId = null;

    public string $editObservacao = '';

    public array $editAdicionaisDisponiveis = [];

    public array $editAdicionaisSelecionados = [];

    public string $saveButtonLabel = 'Salvar Pedido';

    /**
     * Contexto Filament (AtenderPedido): a página é quem mostra o carrinho —
     * numa section própria acima do Cliente, espelhando o AttendOrder do
     * razelfood — então aqui dentro o FAB (Salvar/Itens) e o drawer somem
     * por completo (ver #[On] pedido-incrementar-item/decrementar-item/
     * remover-item/abrir-edicao-item, que repassam os cliques daquela
     * section pra cá). As telas Blade legadas (pedido/create, pedido/edit,
     * sessao_mesa/*, confirmacoes-pedidos) continuam com o drawer, porque
     * dependem do FAB pra submeter o <form id="pedido-form"> delas.
     */
    public bool $carrinhoTopo = false;

    // Clientes da sessão de mesa (apenas quando vindo da view pedido_mesa)
    public array $sessaoMesaClientes = [];

    public ?int $clienteSelecionadoId = null;

    public ?int $editClienteId = null;

    // Modal sabores (meia a meia / terços)
    public bool $saboresModalAberta = false;

    public string $saboresCategoriaNome = '';

    public int $saboresModo = 1;

    public int $saboresMaxModo = 2;

    public array $saboresProdutos = [];

    public array $saboresSelecionados = [];

    // Erro de promoção relâmpago (saldo esgotado/expirou entre a seleção e a
    // confirmação, ou limite por pedido excedido). Exibido como banner.
    public ?string $erroPromocao = null;

    // Opções de oferta de promoção adicional ("leve outro produto por +R$X")
    // disponíveis para o produto selecionado no momento — modal simples
    // (confirmarItem) ou pizza inteira escolhida no modal de sabores
    // (confirmarSabores). Lista vazia quando não há regra vigente para o
    // produto. Nunca some sozinha ao pedido: só entra se
    // $ofertaEscolhidaId apontar pra uma das opções ao confirmar.
    public array $ofertasDisponiveis = [];

    public ?int $ofertaEscolhidaId = null;

    // Estoque insuficiente: erro bloqueia a criação do item (modo BLOQUEAR),
    // aviso apenas informa e deixa o item ser criado (modo AVISAR).
    public ?string $erroEstoque = null;

    public ?string $avisoEstoque = null;

    public function mount(?int $pedidoId = null, array $itensIniciais = []): void
    {
        $this->pedidoId = $pedidoId;

        if ($pedidoId) {
            $this->carregarItensDB();
        } else {
            $this->itens = $itensIniciais;
        }
    }

    public function carregarItensDB(): void
    {
        $this->itens = ItensPedido::where('item_pedido_pedido_id', $this->pedidoId)
            ->where('item_pedido_status', 'INSERIDO')
            ->with(['produto.categoria', 'adicionaisItemPedido.adicional'])
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'produto_id' => $item->item_pedido_produto_id,
                'produto_nome' => $item->produto?->produto_descricao ?? '—',
                'categoria_nome' => $item->produto?->categoria?->categoria_nome ?? '',
                'produto_foto' => $item->produto?->getImagemUrl(),
                'cliente_id' => $item->item_pedido_cliente_id,
                'cliente_nome' => $item->item_pedido_cliente_id
                    ? (collect($this->sessaoMesaClientes)->firstWhere('id', $item->item_pedido_cliente_id)['nome'] ?? null)
                    : null,
                'quantidade' => (float) $item->item_pedido_quantidade,
                'valor_unitario' => (float) $item->item_pedido_valor_unitario,
                'desconto_unit' => $item->item_pedido_quantidade > 0
                    ? round((float) $item->item_pedido_desconto / (float) $item->item_pedido_quantidade, 4)
                    : 0,
                'valor' => (float) $item->item_pedido_valor,
                'desconto' => (float) $item->item_pedido_desconto,
                'adicionais_valor' => (float) $item->item_pedido_valor_adicionais,
                'observacao' => $item->item_pedido_observacao ?? '',
                'promocao_id' => $item->item_pedido_promocao_id,
                'promocao_adicional_regra_id' => $item->item_pedido_promocao_adicional_regra_id,
                'item_origem_id' => $item->item_pedido_origem_id,
                'adicionais' => $item->adicionaisItemPedido->map(fn ($aip) => [
                    'id' => $aip->aip_adicional_id,
                    'nome' => $aip->adicional?->adicional_nome ?? '—',
                    'valor' => (float) $aip->aip_valor_unitario,
                ])->toArray(),
            ])
            ->toArray();
    }

    #[Computed]
    public function categorias()
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        return Categoria::with(['produtos' => fn ($q) => $q->whereIn('produto_tipo', $tiposVenda)->whereNotNull('produto_foto')])
            ->where('categoria_cardapio_garcom', true)
            ->whereHas('produtos', fn ($q) => $q->whereIn('produto_tipo', $tiposVenda))
            ->orderBy('categoria_ordem')
            ->orderBy('categoria_nome')
            ->get();
    }

    #[Computed]
    public function produtos()
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        return Produto::with('categoria')
            ->whereIn('produto_tipo', $tiposVenda)
            ->when($this->busca, fn ($q) => $q->where('produto_descricao', 'like', "%{$this->busca}%"))
            ->when($this->categoriaId, fn ($q) => $q->where('produto_categoria_id', $this->categoriaId))
            ->orderBy('produto_ordem')
            ->orderBy('produto_descricao')
            ->limit(60)
            ->get();
    }

    public function selecionarProduto(int $produtoId): void
    {
        $produto = Produto::with(['ap_produto_id.adicional', 'categoria'])->find($produtoId);
        if (! $produto) {
            return;
        }

        $this->erroPromocao = null;
        $this->ofertasDisponiveis = [];
        $this->ofertaEscolhidaId = null;

        // Categoria com seleção de sabores
        if ($produto->categoria?->categoria_permite_sabores) {
            $this->abrirSaboresModal($produtoId, $produto);

            return;
        }

        // Mesma resolução de preço do cardápio, incluindo promoção relâmpago e
        // promoção adicional (preço de gatilho) vigentes com saldo — o balcão
        // debita os contadores ao confirmar (ver confirmarItem()), igual ao
        // checkout público. Sem pedido gravado ainda não há onde registrar o
        // consumo, então as duas promoções ficam de fora.
        $preco = $this->pedidoId
            ? app(PrecificadorService::class)->resolver($produto)
            : app(PrecificadorService::class)->resolver($produto, considerarRelampago: false, considerarPromoAdicional: false);

        $this->produtoSelecionadoId = $produtoId;
        $this->produtoSelecionado = [
            'id' => $produto->id,
            'nome' => $produto->produto_descricao,
            'categoria_nome' => $produto->categoria?->categoria_nome ?? '',
            'foto' => $produto->getImagemUrl(),
            'preco_venda' => (float) $produto->produto_preco_venda,
            'preco_base' => $preco->valorUnitario,
            'desconto_unit' => $preco->descontoUnitario,
            'promocao_id' => $preco->promocaoId,
            'promocao_adicional_regra_id' => $preco->promocaoAdicionalRegraId,
            'controla_estoque' => (bool) $produto->produto_controla_estoque,
            'saldo_estoque' => (float) $produto->produto_saldo_estoque,
            'unidade_estoque' => $produto->produto_unidade_estoque,
        ];

        if ($this->pedidoId) {
            $this->ofertasDisponiveis = $this->montarOfertasDisponiveis($produtoId);
        }

        $this->quantidade = 1;
        $this->observacao = '';
        $this->adicionaisSelecionados = [];
        $this->adicionaisDisponiveis = $produto->ap_produto_id
            ->filter(fn ($ap) => $ap->adicional !== null)
            ->map(fn ($ap) => [
                'id' => $ap->adicional->id,
                'nome' => $ap->adicional->adicional_nome,
                'valor' => (float) $ap->adicional->adicional_valor,
            ])
            ->toArray();

        $this->modalAberta = true;
    }

    /**
     * Monta a lista de opções de oferta de promoção adicional para um
     * produto-gatilho, se houver regra vigente com saldo — o cliente/atendente
     * escolhe 1 dentre elas. Lista vazia quando não há regra vigente. Nunca
     * adiciona nada ao pedido sozinha — só popula o que o modal precisa para
     * perguntar.
     *
     * @return array<int, array{regra_id: int, oferta_id: int, produto_id: int, nome: string, categoria_nome: string, foto: ?string, valor_adicional: float}>
     */
    protected function montarOfertasDisponiveis(int $produtoGatilhoId): array
    {
        $regra = app(PrecificadorService::class)->regraAdicionalDoProduto($produtoGatilhoId);

        if (! $regra) {
            return [];
        }

        return $regra->ofertasDisponiveis()
            ->map(fn (PromocaoAdicionalOferta $oferta) => [
                'regra_id' => $regra->id,
                'oferta_id' => $oferta->id,
                'produto_id' => $oferta->pao_produto_oferta_id,
                'nome' => $oferta->produtoOferta?->produto_descricao ?? '—',
                'categoria_nome' => $oferta->produtoOferta?->categoria?->categoria_nome ?? '',
                'foto' => $oferta->produtoOferta?->getImagemUrl(),
                'valor_adicional' => (float) $oferta->pao_valor_adicional,
            ])
            ->values()
            ->all();
    }

    /**
     * Marca/desmarca qual das opções de oferta disponíveis foi escolhida
     * (clicar de novo na mesma desmarca — nenhuma oferta selecionada).
     */
    public function selecionarOferta(int $ofertaId): void
    {
        $this->ofertaEscolhidaId = $this->ofertaEscolhidaId === $ofertaId ? null : $ofertaId;
    }

    /**
     * Valida se a oferta escolhida pelo usuário ainda pode ser aceita (limite
     * por pedido). Não lança exceção: se inválida, seta $erroPromocao e
     * devolve null — quem chama decide se ainda assim confirma o gatilho.
     */
    protected function validarOfertaParaConfirmar(): ?array
    {
        if (! $this->pedidoId || ! $this->ofertaEscolhidaId || $this->ofertasDisponiveis === []) {
            return null;
        }

        $oferta = collect($this->ofertasDisponiveis)->firstWhere('oferta_id', $this->ofertaEscolhidaId);
        if (! $oferta) {
            return null;
        }

        $regra = PromocaoAdicionalRegra::find($oferta['regra_id']);
        if (! $regra) {
            return null;
        }

        $promocoes = app(PromocaoAdicionalService::class);

        try {
            $promocoes->validarLimitePorPedido(
                $regra,
                $promocoes->aceitesNoPedido($regra, $this->pedidoId) + 1,
            );
        } catch (PromocaoIndisponivelException $e) {
            $this->erroPromocao = $e->getMessage();

            return null;
        }

        return $oferta;
    }

    /**
     * Cria a linha da oferta aceita, consome o saldo da regra e devolve o
     * array pronto para $this->itens. Roda na própria transação de quem
     * chama (independente da transação do item-gatilho, que já foi
     * commitada antes) — se falhar aqui, o gatilho permanece no pedido.
     * Lança PromocaoIndisponivelException se o limite por pedido ou o saldo
     * da regra foram excedidos entre a seleção e a confirmação.
     *
     * @return array<string, mixed>
     */
    protected function criarItemOferta(
        array $oferta,
        int $itemGatilhoId,
        ?int $clienteId,
        ?string $clienteNome,
        string $categoriaNome
    ): array {
        $regra = PromocaoAdicionalRegra::findOrFail($oferta['regra_id']);
        $ofertaModel = PromocaoAdicionalOferta::findOrFail($oferta['oferta_id']);
        $promocoes = app(PromocaoAdicionalService::class);

        $promocoes->validarLimitePorPedido(
            $regra,
            $promocoes->aceitesNoPedido($regra, $this->pedidoId) + 1,
        );

        $itemModel = ItensPedido::create([
            'item_pedido_pedido_id' => $this->pedidoId,
            'item_pedido_produto_id' => $oferta['produto_id'],
            'item_pedido_promocao_adicional_regra_id' => $regra->id,
            'item_pedido_promocao_adicional_oferta_id' => $ofertaModel->id,
            'item_pedido_origem_id' => $itemGatilhoId,
            'item_pedido_cliente_id' => $clienteId,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => $oferta['valor_adicional'],
            'item_pedido_valor' => $oferta['valor_adicional'],
            'item_pedido_desconto' => 0,
            'item_pedido_desconto_unitario' => 0,
            'item_pedido_valor_adicionais' => 0,
            'item_pedido_observacao' => null,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $promocoes->consumir($itemModel);

        return [
            'id' => $itemModel->id,
            'produto_id' => $oferta['produto_id'],
            'produto_nome' => $oferta['nome'],
            'categoria_nome' => $categoriaNome,
            'produto_foto' => $oferta['foto'],
            'cliente_id' => $clienteId,
            'cliente_nome' => $clienteNome,
            'quantidade' => 1.0,
            'valor_unitario' => $oferta['valor_adicional'],
            'desconto_unit' => 0,
            'valor' => $oferta['valor_adicional'],
            'desconto' => 0,
            'adicionais_valor' => 0,
            'adicionais' => [],
            'observacao' => '',
            'promocao_id' => null,
            'promocao_adicional_regra_id' => $regra->id,
            'item_origem_id' => $itemGatilhoId,
        ];
    }

    // ── Quantidade modal simples ─────────────────────────────────────────────

    public function incrementarQuantidadeModal(): void
    {
        $this->quantidade++;
    }

    public function decrementarQuantidadeModal(): void
    {
        // Só decrementa em passo inteiro quando há mais de 1 unidade.
        // Frações (⅓/½) são definidas pelos botões dedicados.
        if ($this->quantidade > 1) {
            $this->quantidade = $this->quantidade - 1;
        }
    }

    public function setQuantidadeModal(float $valor): void
    {
        // Permite frações (⅓ = 0,3333, ½ = 0,5) sem forçar mínimo de 0,5.
        $this->quantidade = $valor > 0 ? round($valor, 4) : 1;
    }

    // ── Modal de sabores ─────────────────────────────────────────────────────

    protected function abrirSaboresModal(int $produtoId, Produto $produtoBase): void
    {
        $categoria = $produtoBase->categoria;
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        $produtos = Produto::with('categoria')
            ->where('produto_categoria_id', $categoria->id)
            ->whereIn('produto_tipo', $tiposVenda)
            ->orderBy('produto_descricao')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'nome' => $p->produto_descricao,
                'codimentacao' => $p->produto_codimentacao ?? null,
                'foto' => $p->getImagemUrl(),
                // Preço da fração respeita a mesma regra do cardápio: promoção
                // relâmpago "só inteira" volta ao preço normal na fração (ver
                // Produto::precoFracaoCardapio()).
                'preco' => $p->precoFracaoCardapio(),
                'precoOriginal' => (float) $p->produto_preco_venda,
                'temRelampago' => app(PrecificadorService::class)->promocoesVigentesDoProduto($p->id)->isNotEmpty(),
                'controlaEstoque' => (bool) $p->produto_controla_estoque,
                'saldoEstoque' => (float) $p->produto_saldo_estoque,
                'unidadeEstoque' => $p->produto_unidade_estoque,
            ])
            ->toArray();

        $presel = collect($produtos)->firstWhere('id', $produtoId);

        $this->saboresCategoriaNome = $categoria->categoria_nome;
        $this->saboresMaxModo = $produtoBase->maxSaboresCardapio();
        $this->saboresModo = 1;
        $this->saboresProdutos = $produtos;
        $this->saboresSelecionados = $presel ? [$presel] : [];
        $this->saboresModalAberta = true;
        $this->atualizarOfertaSaborUnico();

        if ($presel) {
            $this->js("setTimeout(() => document.getElementById('sabor-item-{$produtoId}')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 320)");
        }
    }

    public function setModoSabores(int $modo): void
    {
        $this->saboresModo = $modo;
        $this->saboresSelecionados = [];
        $this->atualizarOfertaSaborUnico();
    }

    public function toggleSabor(int $produtoId): void
    {
        $produto = collect($this->saboresProdutos)->firstWhere('id', $produtoId);
        if (! $produto) {
            return;
        }

        $jaSelected = collect($this->saboresSelecionados)->contains('id', $produtoId);

        if ($jaSelected) {
            $this->saboresSelecionados = array_values(
                array_filter($this->saboresSelecionados, fn ($s) => $s['id'] !== $produtoId)
            );
        } elseif ($this->saboresModo === 1) {
            $this->saboresSelecionados = [$produto];
        } elseif (count($this->saboresSelecionados) < $this->saboresModo) {
            $this->saboresSelecionados[] = $produto;
        }

        $this->atualizarOfertaSaborUnico();
    }

    /**
     * A oferta de promoção adicional só faz sentido para pizza inteira (um
     * único sabor) — meia a meia/terços têm sua própria regra de rateio e
     * ficam fora do escopo desta promoção. Recalculada a cada mudança de modo
     * ou seleção de sabor.
     */
    protected function atualizarOfertaSaborUnico(): void
    {
        $this->ofertasDisponiveis = [];
        $this->ofertaEscolhidaId = null;

        if (! $this->pedidoId || $this->saboresModo !== 1 || count($this->saboresSelecionados) !== 1) {
            return;
        }

        $this->ofertasDisponiveis = $this->montarOfertasDisponiveis($this->saboresSelecionados[0]['id']);
    }

    public function confirmarSabores(): void
    {
        $sel = $this->saboresSelecionados;
        if (count($sel) !== $this->saboresModo) {
            return;
        }

        $this->erroPromocao = null;
        $this->erroEstoque = null;
        $this->avisoEstoque = null;

        // Refaz o rateio com a mesma fonte da verdade do checkout público
        // (PrecificadorService::ratearCombo) em vez de recalcular na mão aqui:
        // evita a fração e o combo divergirem sobre quando a promoção vale.
        $produtosPorId = Produto::with('categoria')
            ->whereIn('id', collect($sel)->pluck('id'))
            ->get()
            ->keyBy('id');

        $produtosOrdenados = collect($sel)
            ->map(fn ($s) => $produtosPorId->get($s['id']))
            ->filter()
            ->values()
            ->all();

        if (count($produtosOrdenados) !== count($sel)) {
            return;
        }

        $precificador = app(PrecificadorService::class);
        $promocoes = app(PromocaoRelampagoService::class);

        // Pizza inteira (um só sabor) resolve como item avulso — inclui
        // relâmpago mesmo fora de uma promoção "que permite sabores". O rateio
        // de combo (ratearCombo) só entra em jogo com 2+ sabores, igual ao
        // checkout público (CardapioCheckoutController).
        if (count($produtosOrdenados) === 1) {
            $preco = $precificador->resolver($produtosOrdenados[0]);
            $linhaUnica = ItensPedido::calcularLinha(1, $preco->valorUnitario, $preco->descontoUnitario);

            $rateio = [[
                'produto_id' => $produtosOrdenados[0]->id,
                'quantidade' => 1.0,
                'valor_unitario' => $linhaUnica['valor_unitario'],
                'desconto' => $linhaUnica['desconto'],
                'desconto_unitario' => $preco->descontoUnitario,
                'valor' => $linhaUnica['valor'],
                'promocao_id' => $preco->promocaoId,
                'promocao_adicional_regra_id' => $preco->promocaoAdicionalRegraId,
            ]];
        } else {
            // Meia a meia/terços ficam fora do escopo da promoção adicional —
            // cada sabor usa o preço cheio (sem override) para o rateio, igual
            // ao tratamento que já existia para o preço promocional do produto.
            $rateio = $precificador->ratearCombo($produtosOrdenados, qtd: 1);
        }

        if ($this->pedidoId) {
            $estoque = app(EstoqueService::class);
            $avisos = [];

            try {
                foreach ($rateio as $linha) {
                    $produtoLinha = $produtosPorId->get($linha['produto_id']);

                    if (! $produtoLinha) {
                        continue;
                    }

                    array_push($avisos, ...$estoque->validarDisponibilidade($produtoLinha, (float) $linha['quantidade']));
                }
            } catch (EstoqueInsuficienteException $e) {
                $this->erroEstoque = $e->getMessage();

                return;
            }

            if ($avisos !== []) {
                $this->avisoEstoque = implode(' | ', $avisos);
            }
        }

        $promocaoId = $rateio[0]['promocao_id'] ?? null;

        if ($promocaoId && $this->pedidoId) {
            $promocao = PromocaoRelampago::find($promocaoId);
            $qtdTotal = (float) collect($rateio)->sum('quantidade');

            try {
                $promocoes->validarLimitePorPedido(
                    $promocao,
                    $promocoes->quantidadeNoPedido($promocao, $this->pedidoId) + $qtdTotal,
                );
            } catch (PromocaoIndisponivelException $e) {
                $this->erroPromocao = $e->getMessage();

                return;
            }
        }

        $clienteNome = $this->clienteSelecionadoId
            ? (collect($this->sessaoMesaClientes)->firstWhere('id', $this->clienteSelecionadoId)['nome'] ?? null)
            : null;

        // ofertasDisponiveis só é preenchido para pizza inteira (1 sabor) — ver
        // atualizarOfertaSaborUnico(). Validado ANTES de criar o gatilho: se o
        // limite estourou, a promoção fica de fora, mas a pizza ainda é
        // adicionada normalmente.
        $ofertaParaConfirmar = $this->validarOfertaParaConfirmar();

        $novosItens = [];
        $idGatilhoParaOferta = null;

        try {
            if ($this->pedidoId) {
                DB::transaction(function () use ($rateio, $produtosPorId, $clienteNome, $promocoes, &$novosItens, &$idGatilhoParaOferta) {
                    foreach ($rateio as $linha) {
                        $itemModel = ItensPedido::create([
                            'item_pedido_pedido_id' => $this->pedidoId,
                            'item_pedido_produto_id' => $linha['produto_id'],
                            'item_pedido_promocao_id' => $linha['promocao_id'],
                            'item_pedido_promocao_adicional_regra_id' => $linha['promocao_adicional_regra_id'] ?? null,
                            'item_pedido_cliente_id' => $this->clienteSelecionadoId ?: null,
                            'item_pedido_quantidade' => $linha['quantidade'],
                            'item_pedido_valor_unitario' => $linha['valor_unitario'],
                            'item_pedido_valor' => $linha['valor'],
                            'item_pedido_desconto' => $linha['desconto'],
                            'item_pedido_desconto_unitario' => $linha['desconto_unitario'],
                            'item_pedido_valor_adicionais' => 0,
                            'item_pedido_observacao' => null,
                            'item_pedido_status' => 'INSERIDO',
                        ]);

                        if ($linha['promocao_id']) {
                            $promocoes->consumir($itemModel);
                        }

                        $produto = $produtosPorId->get($linha['produto_id']);

                        $novosItens[] = [
                            'id' => $itemModel->id,
                            'produto_id' => $linha['produto_id'],
                            'produto_nome' => $produto?->produto_descricao ?? '—',
                            'categoria_nome' => $this->saboresCategoriaNome,
                            'produto_foto' => $produto?->getImagemUrl(),
                            'cliente_id' => $this->clienteSelecionadoId ?: null,
                            'cliente_nome' => $clienteNome,
                            'quantidade' => $linha['quantidade'],
                            'valor_unitario' => $linha['valor_unitario'],
                            'desconto_unit' => $linha['desconto_unitario'],
                            'valor' => $linha['valor'],
                            'desconto' => $linha['desconto'],
                            'adicionais_valor' => 0,
                            'adicionais' => [],
                            'observacao' => '',
                            'promocao_id' => $linha['promocao_id'],
                            'promocao_adicional_regra_id' => $linha['promocao_adicional_regra_id'] ?? null,
                            'item_origem_id' => null,
                        ];

                        // Só há 1 linha no rateio quando é pizza inteira — mesmo
                        // caso em que ofertaParaConfirmar pode estar preenchido.
                        $idGatilhoParaOferta = $itemModel->id;
                    }
                });
            } else {
                // Sem pedido gravado ainda: mantém só a prévia visual (sem
                // debitar promoção — não há item persistido para o ledger).
                foreach ($rateio as $linha) {
                    $produto = $produtosPorId->get($linha['produto_id']);

                    $novosItens[] = [
                        'id' => uniqid('tmp_'),
                        'produto_id' => $linha['produto_id'],
                        'produto_nome' => $produto?->produto_descricao ?? '—',
                        'categoria_nome' => $this->saboresCategoriaNome,
                        'produto_foto' => $produto?->getImagemUrl(),
                        'cliente_id' => $this->clienteSelecionadoId ?: null,
                        'cliente_nome' => $clienteNome,
                        'quantidade' => $linha['quantidade'],
                        'valor_unitario' => $linha['valor_unitario'],
                        'desconto_unit' => $linha['desconto_unitario'],
                        'valor' => $linha['valor'],
                        'desconto' => $linha['desconto'],
                        'adicionais_valor' => 0,
                        'adicionais' => [],
                        'observacao' => '',
                        'promocao_id' => null,
                    ];
                }
            }
        } catch (PromocaoIndisponivelException $e) {
            $this->erroPromocao = $e->getMessage();

            return;
        }

        // Transação separada da do(s) sabor(es): se a oferta falhar aqui, a
        // pizza já foi persistida e continua no pedido — só a oferta some.
        if ($ofertaParaConfirmar && $idGatilhoParaOferta) {
            try {
                $novosItens[] = DB::transaction(fn () => $this->criarItemOferta(
                    $ofertaParaConfirmar,
                    $idGatilhoParaOferta,
                    $this->clienteSelecionadoId ?: null,
                    $clienteNome,
                    $this->saboresCategoriaNome,
                ));
            } catch (PromocaoIndisponivelException $e) {
                $this->erroPromocao = $e->getMessage();
            }
        }

        array_push($this->itens, ...$novosItens);

        $this->fecharSaboresModal();
        $this->notificarPai();
    }

    public function fecharSaboresModal(): void
    {
        $this->saboresModalAberta = false;
        $this->saboresSelecionados = [];
        $this->saboresProdutos = [];
        $this->clienteSelecionadoId = null;
        $this->ofertasDisponiveis = [];
        $this->ofertaEscolhidaId = null;
    }

    // ── Adicionais ───────────────────────────────────────────────────────────

    public function toggleAdicional(int $adicionalId): void
    {
        if (in_array($adicionalId, $this->adicionaisSelecionados)) {
            $this->adicionaisSelecionados = array_values(
                array_filter($this->adicionaisSelecionados, fn ($id) => $id !== $adicionalId)
            );
        } else {
            $this->adicionaisSelecionados[] = $adicionalId;
        }
    }

    public function confirmarItem(): void
    {
        if (! $this->produtoSelecionado) {
            return;
        }

        $this->erroPromocao = null;
        $this->erroEstoque = null;
        $this->avisoEstoque = null;

        $precoBase = $this->produtoSelecionado['preco_base'];
        $descontoUnit = $this->produtoSelecionado['desconto_unit'];
        $promocaoId = $this->produtoSelecionado['promocao_id'] ?? null;
        $promocaoAdicionalRegraIdGatilho = $this->produtoSelecionado['promocao_adicional_regra_id'] ?? null;

        $promocoes = app(PromocaoRelampagoService::class);

        // Valida o limite da oferta ANTES de criar o gatilho: se estourou,
        // a promoção fica de fora (com aviso), mas o produto principal ainda
        // é adicionado normalmente — não faz sentido barrar a pizza inteira
        // só porque a brotinho não pode mais ser oferecida neste pedido.
        $ofertaParaConfirmar = $this->validarOfertaParaConfirmar();

        if ($this->pedidoId) {
            try {
                $avisos = app(EstoqueService::class)->validarDisponibilidade(
                    Produto::findOrFail($this->produtoSelecionadoId),
                    $this->quantidade,
                );

                if ($avisos !== []) {
                    $this->avisoEstoque = implode(' | ', $avisos);
                }
            } catch (EstoqueInsuficienteException $e) {
                $this->erroEstoque = $e->getMessage();

                return;
            }
        }

        if ($promocaoId && $this->pedidoId) {
            $promocao = PromocaoRelampago::find($promocaoId);

            try {
                $promocoes->validarLimitePorPedido(
                    $promocao,
                    $promocoes->quantidadeNoPedido($promocao, $this->pedidoId) + $this->quantidade,
                );
            } catch (PromocaoIndisponivelException $e) {
                $this->erroPromocao = $e->getMessage();

                return;
            }
        }

        $adicionaisValor = 0;
        $adicionaisList = [];
        foreach ($this->adicionaisDisponiveis as $adicional) {
            if (in_array($adicional['id'], $this->adicionaisSelecionados)) {
                $adicionaisValor += $adicional['valor'];
                $adicionaisList[] = $adicional;
            }
        }

        // Regra de negócio centralizada no model: valor líquido = (qtd × unit) − desconto + adicionais
        $linha = ItensPedido::calcularLinha($this->quantidade, $precoBase, $descontoUnit, $adicionaisValor);
        $valorItem = $linha['valor'];
        $descontoTotal = $linha['desconto'];

        $clienteNome = $this->clienteSelecionadoId
            ? (collect($this->sessaoMesaClientes)->firstWhere('id', $this->clienteSelecionadoId)['nome'] ?? null)
            : null;

        $itemOfertaArray = null;

        if ($this->pedidoId) {
            try {
                $itemModel = DB::transaction(function () use (
                    $promocaoId, $promocaoAdicionalRegraIdGatilho, $precoBase, $descontoUnit, $valorItem, $descontoTotal,
                    $adicionaisValor, $adicionaisList, $promocoes
                ) {
                    $itemModel = ItensPedido::create([
                        'item_pedido_pedido_id' => $this->pedidoId,
                        'item_pedido_produto_id' => $this->produtoSelecionadoId,
                        'item_pedido_promocao_id' => $promocaoId,
                        'item_pedido_promocao_adicional_regra_id' => $promocaoAdicionalRegraIdGatilho,
                        'item_pedido_cliente_id' => $this->clienteSelecionadoId ?: null,
                        'item_pedido_quantidade' => $this->quantidade,
                        'item_pedido_valor_unitario' => $precoBase,
                        'item_pedido_valor' => $valorItem,
                        'item_pedido_desconto' => $descontoTotal,
                        'item_pedido_desconto_unitario' => $descontoUnit,
                        'item_pedido_valor_adicionais' => $adicionaisValor,
                        'item_pedido_observacao' => $this->observacao ?: null,
                        'item_pedido_status' => 'INSERIDO',
                    ]);

                    foreach ($adicionaisList as $adicional) {
                        AdicionaisItemPedido::create([
                            'aip_item_pedido_id' => $itemModel->id,
                            'aip_adicional_id' => $adicional['id'],
                            'aip_quantidade' => 1,
                            'aip_valor_unitario' => $adicional['valor'],
                            'aip_valor_total' => $adicional['valor'],
                        ]);
                    }

                    if ($promocaoId) {
                        $promocoes->consumir($itemModel);
                    }

                    return $itemModel;
                });
            } catch (PromocaoIndisponivelException $e) {
                $this->erroPromocao = $e->getMessage();

                return;
            }

            $itemId = $itemModel->id;

            // Transação separada e independente da do gatilho: se a oferta
            // falhar aqui (saldo esgotou entre a seleção e a confirmação), o
            // produto principal já foi persistido e continua no pedido — só a
            // oferta some, com aviso.
            if ($ofertaParaConfirmar) {
                try {
                    $itemOfertaArray = DB::transaction(fn () => $this->criarItemOferta(
                        $ofertaParaConfirmar,
                        $itemModel->id,
                        $this->clienteSelecionadoId ?: null,
                        $clienteNome,
                        $this->produtoSelecionado['categoria_nome'] ?? '',
                    ));
                } catch (PromocaoIndisponivelException $e) {
                    $this->erroPromocao = $e->getMessage();
                }
            }
        } else {
            $itemId = uniqid('tmp_');
        }

        $this->itens[] = [
            'id' => $itemId,
            'produto_id' => $this->produtoSelecionadoId,
            'produto_nome' => $this->produtoSelecionado['nome'],
            'categoria_nome' => $this->produtoSelecionado['categoria_nome'] ?? '',
            'produto_foto' => $this->produtoSelecionado['foto'],
            'cliente_id' => $this->clienteSelecionadoId ?: null,
            'cliente_nome' => $clienteNome,
            'quantidade' => $this->quantidade,
            'valor_unitario' => $precoBase,
            'desconto_unit' => $descontoUnit,
            'valor' => $valorItem,
            'desconto' => $descontoTotal,
            'adicionais_valor' => $adicionaisValor,
            'adicionais' => $adicionaisList,
            'observacao' => $this->observacao,
            'promocao_id' => $promocaoId,
            'promocao_adicional_regra_id' => $promocaoAdicionalRegraIdGatilho,
            'item_origem_id' => null,
        ];

        if ($itemOfertaArray) {
            $this->itens[] = $itemOfertaArray;
        }

        $this->fecharModal();
        $this->notificarPai();
    }

    /** Dispensa o toast de erro/aviso de estoque exibido dentro dos modais. */
    public function fecharToastEstoque(): void
    {
        $this->erroEstoque = null;
        $this->avisoEstoque = null;
    }

    public function fecharModal(): void
    {
        $this->modalAberta = false;
        $this->produtoSelecionado = null;
        $this->produtoSelecionadoId = null;
        $this->adicionaisDisponiveis = [];
        $this->adicionaisSelecionados = [];
        $this->clienteSelecionadoId = null;
        $this->ofertasDisponiveis = [];
        $this->ofertaEscolhidaId = null;
    }

    // ── Quantidade itens na lista ────────────────────────────────────────────

    public function incrementarQtd(string $itemId): void
    {
        $this->ajustarQtd($itemId, +1);
    }

    public function decrementarQtd(string $itemId): void
    {
        $this->ajustarQtd($itemId, -1);
    }

    protected function ajustarQtd(string $itemId, float $delta): void
    {
        foreach ($this->itens as &$item) {
            if ((string) $item['id'] !== (string) $itemId) {
                continue;
            }

            // Item promocional tem preço E quantidade congelados: o ledger da
            // promoção (promocao_consumos / promocao_adicional_consumos) grava
            // um único evento imutável por item_pedido_id, então não dá para
            // debitar/estornar parcialmente por aqui. Para mudar a quantidade,
            // remova o item e adicione de novo (ver removerItem()). A linha da
            // oferta (item_origem_id setado) também é sempre quantidade 1.
            if (! empty($item['promocao_id']) || ! empty($item['promocao_adicional_regra_id']) || ! empty($item['item_origem_id'])) {
                return;
            }

            // Incrementa/decrementa na grade da fração: inteiro (1), meia (½) ou
            // terço (⅓). Trabalha em "unidades da fração" para evitar resíduo
            // (ex.: ⅓ → 0,3333 → 0,6667 → 1,0), sem corromper a precisão.
            $qtdAtual = (float) $item['quantidade'];
            $fracPart = $qtdAtual - floor($qtdAtual);
            if ($fracPart < 0.01) {
                $denom = 1;          // item inteiro
            } elseif (abs($fracPart - 0.5) < 0.02) {
                $denom = 2;          // meia
            } else {
                $denom = 3;          // terço
            }

            $unidades = max(1, (int) round($qtdAtual * $denom) + (int) $delta);
            $novaQtd = $denom === 1 ? (float) $unidades : round($unidades / $denom, 4);

            $item['quantidade'] = $novaQtd;
            $item['desconto'] = round(($item['desconto_unit'] ?? 0) * $novaQtd, 2);
            $item['valor'] = round(($item['valor_unitario'] * $novaQtd) - $item['desconto'] + $item['adicionais_valor'], 2);

            if ($this->pedidoId && is_numeric($itemId)) {
                ItensPedido::find($itemId)?->update([
                    'item_pedido_quantidade' => $novaQtd,
                    'item_pedido_valor' => $item['valor'],
                    'item_pedido_desconto' => $item['desconto'],
                ]);
            }
            break;
        }
        unset($item);

        $this->notificarPai();
    }

    /**
     * A section de Carrinho fixa no topo da página AtenderPedido (fora deste
     * componente, ver $carrinhoTopo) reaproveita os nomes de método
     * incrementarQtd/decrementarQtd/removerItem/abrirEditModal via o mesmo
     * partial de linha — mas ela roda no componente Page, então esses
     * cliques chegam aqui como eventos repassados em vez de chamada direta.
     */
    #[On('pedido-incrementar-item')]
    public function onIncrementarItemExterno(string $itemId): void
    {
        $this->incrementarQtd($itemId);
    }

    #[On('pedido-decrementar-item')]
    public function onDecrementarItemExterno(string $itemId): void
    {
        $this->decrementarQtd($itemId);
    }

    #[On('pedido-remover-item')]
    public function onRemoverItemExterno(string $itemId): void
    {
        $this->removerItem($itemId);
    }

    #[On('pedido-abrir-edicao-item')]
    public function onAbrirEdicaoItemExterno(string $itemId): void
    {
        $this->abrirEditModal($itemId);
    }

    public function removerItem(string $itemId): void
    {
        // ids das linhas de oferta removidas em cascata (item-gatilho removido
        // leva a(s) linha(s) de oferta vinculada(s) junto — não faz sentido
        // manter a brotinho promocional sem a pizza que a habilitou).
        $idsOfertaRemovidos = [];

        if ($this->pedidoId && is_numeric($itemId)) {
            DB::transaction(function () use ($itemId, &$idsOfertaRemovidos) {
                $item = ItensPedido::find($itemId);
                if (! $item) {
                    return;
                }

                if ($item->item_pedido_promocao_id) {
                    app(PromocaoRelampagoService::class)->estornarItem($item);
                }

                // Este item é um gatilho com oferta(s) vinculada(s)?
                if (! $item->item_pedido_origem_id) {
                    $idsOfertaRemovidos = app(PromocaoAdicionalService::class)->estornarItensDoGatilho($item);
                    ItensPedido::whereIn('id', $idsOfertaRemovidos)->delete();
                } elseif ($item->item_pedido_promocao_adicional_regra_id) {
                    // Este item é a própria linha de oferta sendo removida
                    // isoladamente (o gatilho continua no pedido).
                    app(PromocaoAdicionalService::class)->estornarItemOferta($item);
                }

                $item->delete();
            });
        }

        $idsRemovidos = array_merge([$itemId], array_map('strval', $idsOfertaRemovidos));

        $this->itens = array_values(
            array_filter($this->itens, fn ($i) => ! in_array((string) $i['id'], $idsRemovidos, true))
        );

        $this->notificarPai();
    }

    // ── Edição de item existente ─────────────────────────────────────────────

    public function abrirEditModal(string $itemId): void
    {
        $item = collect($this->itens)->firstWhere('id', $itemId);
        if (! $item) {
            return;
        }

        $this->editItemId = $itemId;
        $this->editObservacao = $item['observacao'] ?? '';
        $this->editAdicionaisSelecionados = array_column($item['adicionais'] ?? [], 'id');
        $this->editClienteId = $item['cliente_id'] ?? null;

        $produto = Produto::with('ap_produto_id.adicional')->find($item['produto_id']);
        $this->editAdicionaisDisponiveis = $produto?->ap_produto_id
            ->filter(fn ($ap) => $ap->adicional !== null)
            ->map(fn ($ap) => [
                'id' => $ap->adicional->id,
                'nome' => $ap->adicional->adicional_nome,
                'valor' => (float) $ap->adicional->adicional_valor,
            ])
            ->toArray() ?? [];

        $this->editModalAberta = true;
    }

    public function toggleEditAdicional(int $adicionalId): void
    {
        if (in_array($adicionalId, $this->editAdicionaisSelecionados)) {
            $this->editAdicionaisSelecionados = array_values(
                array_filter($this->editAdicionaisSelecionados, fn ($id) => $id !== $adicionalId)
            );
        } else {
            $this->editAdicionaisSelecionados[] = $adicionalId;
        }
    }

    public function salvarEdicaoItem(): void
    {
        $itemId = $this->editItemId;
        if (! $itemId) {
            return;
        }

        $adicionaisList = [];
        $adicionaisValor = 0;
        foreach ($this->editAdicionaisDisponiveis as $adicional) {
            if (in_array($adicional['id'], $this->editAdicionaisSelecionados)) {
                $adicionaisValor += $adicional['valor'];
                $adicionaisList[] = $adicional;
            }
        }

        $editClienteNome = $this->editClienteId
            ? (collect($this->sessaoMesaClientes)->firstWhere('id', $this->editClienteId)['nome'] ?? null)
            : null;

        foreach ($this->itens as &$item) {
            if ((string) $item['id'] !== (string) $itemId) {
                continue;
            }

            // Preserva o valor base (já distribuído em centavos nas frações de
            // sabor) e apenas troca os adicionais — evita re-arredondar a fração.
            $baseSemAdic = round((float) $item['valor'] - (float) ($item['adicionais_valor'] ?? 0), 2);
            $item['observacao'] = $this->editObservacao;
            $item['adicionais'] = $adicionaisList;
            $item['adicionais_valor'] = $adicionaisValor;
            $item['valor'] = round($baseSemAdic + $adicionaisValor, 2);
            $item['cliente_id'] = $this->editClienteId ?: null;
            $item['cliente_nome'] = $editClienteNome;
            break;
        }
        unset($item);

        if ($this->pedidoId && is_numeric($itemId)) {
            $itemModel = ItensPedido::find($itemId);
            if ($itemModel) {
                // Mantém o valor base distribuído (líquido sem adicionais) e
                // apenas soma os novos adicionais — preserva a fração de sabor.
                $baseSemAdic = round((float) $itemModel->item_pedido_valor - (float) $itemModel->item_pedido_valor_adicionais, 2);
                $novoValor = round($baseSemAdic + $adicionaisValor, 2);
                $itemModel->update([
                    'item_pedido_observacao' => $this->editObservacao ?: null,
                    'item_pedido_cliente_id' => $this->editClienteId ?: null,
                    'item_pedido_valor_adicionais' => $adicionaisValor,
                    'item_pedido_valor' => $novoValor,
                ]);

                AdicionaisItemPedido::where('aip_item_pedido_id', $itemId)->delete();
                foreach ($adicionaisList as $adicional) {
                    AdicionaisItemPedido::create([
                        'aip_item_pedido_id' => $itemId,
                        'aip_adicional_id' => $adicional['id'],
                        'aip_quantidade' => 1,
                        'aip_valor_unitario' => $adicional['valor'],
                        'aip_valor_total' => $adicional['valor'],
                    ]);
                }
            }
        }

        $this->fecharEditModal();
        $this->notificarPai();
    }

    public function fecharEditModal(): void
    {
        $this->editModalAberta = false;
        $this->editItemId = null;
        $this->editObservacao = '';
        $this->editAdicionaisDisponiveis = [];
        $this->editAdicionaisSelecionados = [];
        $this->editClienteId = null;
    }

    protected function notificarPai(): void
    {
        $this->dispatch('itens-pedido-atualizados', itens: $this->itens);
    }

    public function render()
    {
        return view('livewire.pedido-produto-selector');
    }
}
