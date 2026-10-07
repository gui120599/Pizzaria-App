<?php

namespace App\Livewire;

use App\Enums\CanalLancamentoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Exceptions\ComboSaboresInvalidoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\ItemIndisponivelException;
use App\Exceptions\PerguntaNaoRespondidaException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Produto;
use App\Models\PromocaoAdicionalOferta;
use App\Services\ItemSolicitado;
use App\Services\LancamentoItemPedidoService;
use App\Services\LinhaPrecificada;
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

    /**
     * Pessoa da mesa para quem o garçom está lançando ("Lançando para…" no
     * Painel do Garçom): cada item novo nasce para ela, e a seleção volta a
     * ela depois de cada item. Null = mesa geral.
     */
    public ?int $clientePadraoId = null;

    /**
     * Painel do Garçom no desktop (≥ lg): produtos em grade e, em vez do
     * botão flutuante/gaveta, o carrinho fica num painel lateral da página
     * (alimentado por itens-pedido-atualizados). Abaixo de lg, igual ao celular.
     */
    public bool $layoutDesktop = false;

    public ?int $editClienteId = null;

    // Modal sabores (meia a meia / terços)
    public bool $saboresModalAberta = false;

    /** Observação da pizza escolhida no modal de sabores (ex.: "sem cebola"). */
    public string $saboresObservacao = '';

    public string $saboresCategoriaNome = '';

    public int $saboresModo = 1;

    /**
     * Opções de quantidade (≥ 2 sabores) da categoria, já limitadas pelo teto
     * da promoção vigente — o "Inteiro" (1) é sempre exibido à parte.
     *
     * @var array<int, array{quantidade: int, descricao: string}>
     */
    public array $saboresOpcoes = [];

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

    /**
     * Perguntas do item aberto no modal (simples ou de sabores), no formato de
     * Pergunta::paraTela(), e as opções marcadas por pergunta. O servidor
     * revalida tudo ao lançar (LancamentoItemPedidoService).
     *
     * @var list<array{id: int, texto: string, minimo: int, maximo: int, opcoes: list<array{id: int, nome: string, valor: float}>}>
     */
    public array $perguntasDisponiveis = [];

    /** @var array<int, list<int>> pergunta_id => opções marcadas */
    public array $respostas = [];

    public ?int $saboresCategoriaId = null;

    public function mount(?int $pedidoId = null, array $itensIniciais = [], ?int $clientePadraoId = null, bool $layoutDesktop = false): void
    {
        $this->pedidoId = $pedidoId;
        $this->clientePadraoId = $clientePadraoId;
        $this->clienteSelecionadoId = $clientePadraoId;
        $this->layoutDesktop = $layoutDesktop;

        if ($pedidoId) {
            $this->carregarItensDB();
        } else {
            $this->itens = $itensIniciais;
        }

        // No desktop o carrinho é um painel da página (Painel do Garçom):
        // ela precisa dos itens já gravados no rascunho desde o início.
        if ($layoutDesktop) {
            $this->notificarPai();
        }
    }

    public function carregarItensDB(): void
    {
        $this->itens = ItensPedido::where('item_pedido_pedido_id', $this->pedidoId)
            ->where('item_pedido_status', 'INSERIDO')
            ->with(['produto.categoria', 'adicionaisItemPedido.adicional'])
            ->get()
            ->map(fn (ItensPedido $item) => $this->itemParaLista($item))
            ->toArray();
    }

    /**
     * Linha da lista do carrinho a partir de um item gravado ou da prévia (sem
     * id, quando ainda não há pedido gravado).
     *
     * @param  ?list<array{id: int, nome: string, valor: float}>  $adicionais  Na prévia, que não tem adicionais gravados
     * @return array<string, mixed>
     */
    protected function itemParaLista(ItensPedido $item, ?array $adicionais = null): array
    {
        $quantidade = (float) $item->item_pedido_quantidade;

        return [
            'id' => $item->id ?? uniqid('tmp_'),
            'produto_id' => $item->item_pedido_produto_id,
            'produto_nome' => $item->ehMultiSabor()
                ? $item->descricaoSabores()
                : ($item->produto?->produto_descricao ?? '—'),
            'categoria_nome' => $item->produto?->categoria?->categoria_nome ?? '',
            'multi_sabor' => $item->ehMultiSabor(),
            'sabores' => $item->item_pedido_sabores,
            'sabores_linhas' => $item->linhasSabores(),
            'respostas' => $item->item_pedido_respostas,
            'respostas_linhas' => $item->linhasRespostas(),
            'produto_foto' => $item->produto?->getImagemUrl(),
            'cliente_id' => $item->item_pedido_cliente_id,
            'cliente_nome' => $this->nomeDoCliente($item->item_pedido_cliente_id),
            'quantidade' => $quantidade,
            'valor_unitario' => (float) $item->item_pedido_valor_unitario,
            'desconto_unit' => $item->item_pedido_desconto_unitario !== null
                ? (float) $item->item_pedido_desconto_unitario
                : ($quantidade > 0 ? round((float) $item->item_pedido_desconto / $quantidade, 4) : 0),
            'valor' => (float) $item->item_pedido_valor,
            'desconto' => (float) $item->item_pedido_desconto,
            'adicionais_valor' => (float) $item->item_pedido_valor_adicionais,
            'observacao' => $item->item_pedido_observacao ?? '',
            'promocao_id' => $item->item_pedido_promocao_id,
            'promocao_adicional_regra_id' => $item->item_pedido_promocao_adicional_regra_id,
            'item_origem_id' => $item->item_pedido_origem_id,
            'adicionais' => $adicionais ?? $item->adicionaisItemPedido->map(fn ($aip) => [
                'id' => $aip->aip_adicional_id,
                'nome' => $aip->adicional?->adicional_nome ?? '—',
                'valor' => (float) $aip->aip_valor_unitario,
            ])->toArray(),
        ];
    }

    protected function nomeDoCliente(?int $clienteId): ?string
    {
        return $clienteId
            ? (collect($this->sessaoMesaClientes)->firstWhere('id', $clienteId)['nome'] ?? null)
            : null;
    }

    #[Computed]
    public function categorias()
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        return Categoria::with(['produtos' => fn ($q) => $q->whereIn('produto_tipo', $tiposVenda)->where('produto_cardapio_garcom', true)->whereNotNull('produto_foto')])
            ->where('categoria_cardapio_garcom', true)
            ->whereHas('produtos', fn ($q) => $q->whereIn('produto_tipo', $tiposVenda)->where('produto_cardapio_garcom', true))
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
            ->where('produto_cardapio_garcom', true)
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
        $this->perguntasDisponiveis = $produto->perguntasAplicaveis()->map->paraTela()->values()->all();
        $this->respostas = [];

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
     * Produto da oferta escolhida entre as opções exibidas. O servidor
     * revalida no lançamento se ela ainda vale (LancamentoItemPedidoService).
     */
    protected function ofertaProdutoEscolhido(): ?int
    {
        if (! $this->ofertaEscolhidaId) {
            return null;
        }

        $oferta = collect($this->ofertasDisponiveis)->firstWhere('oferta_id', $this->ofertaEscolhidaId);

        return $oferta ? (int) $oferta['produto_id'] : null;
    }

    /**
     * Lança a linha já precificada. Com pedido gravado, valida estoque e
     * limites de promoção e grava; a oferta vai numa transação à parte — se
     * ela falhar, o item continua no pedido e só a oferta some, com aviso.
     * Sem pedido gravado, só monta a prévia na lista.
     *
     * @return bool false quando o item não entrou (o modal fica aberto com o erro)
     */
    protected function lancar(LinhaPrecificada $linha): bool
    {
        if (! $this->pedidoId) {
            // Prévia já precificada sem promoção: não há onde registrar o consumo.
            $this->itens[] = $this->itemParaLista($linha->previa(), $linha->adicionais);

            return true;
        }

        $lancamento = app(LancamentoItemPedidoService::class);

        try {
            $avisos = $lancamento->validarEstoque([$linha]);
            $lancamento->validarLimitesPromocao([$linha], $this->pedidoId);
        } catch (EstoqueInsuficienteException $e) {
            $this->erroEstoque = $e->getMessage();

            return false;
        } catch (PromocaoIndisponivelException $e) {
            $this->erroPromocao = $e->getMessage();

            return false;
        }

        if ($avisos !== []) {
            $this->avisoEstoque = implode(' | ', $avisos);
        }

        if ($this->ofertaEscolhidaId && ! $linha->oferta) {
            $this->erroPromocao = 'A oferta escolhida não está mais disponível.';
        }

        // Oferta acima do limite por pedido fica de fora (com aviso), mas o
        // item principal ainda entra.
        if ($linha->oferta) {
            try {
                $lancamento->validarLimitesOferta([$linha], $this->pedidoId);
            } catch (PromocaoIndisponivelException $e) {
                $this->erroPromocao = $e->getMessage();
                $linha = $linha->semOferta();
            }
        }

        try {
            $novos = [$lancamento->gravar($this->pedidoId, $linha)];
        } catch (PromocaoIndisponivelException $e) {
            $this->erroPromocao = $e->getMessage();

            return false;
        }

        try {
            if ($oferta = $lancamento->gravarOferta($this->pedidoId, $novos[0], $linha)) {
                $novos[] = $oferta;
            }
        } catch (PromocaoIndisponivelException $e) {
            $this->erroPromocao = $e->getMessage();
        }

        foreach ($novos as $novo) {
            $this->itens[] = $this->itemParaLista($novo->load(['produto.categoria', 'adicionaisItemPedido.adicional']));
        }

        return true;
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
            ->where('produto_cardapio_garcom', true)
            ->orderBy('produto_descricao')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'nome' => $p->produto_descricao,
                'codimentacao' => $p->produto_codimentacao ?? null,
                'foto' => $p->getImagemUrl(),
                // Preço da fração respeita a mesma regra do cardápio: promoção
                // relâmpago "só inteira" volta ao preço normal na fração (ver
                // Produto::precoFracaoCardapio()). Na prévia (sem pedido
                // gravado) não há promoção, como em confirmarSabores().
                'preco' => $this->pedidoId
                    ? $p->precoFracaoCardapio()
                    : app(PrecificadorService::class)->resolver($p, considerarRelampago: false, considerarPromoAdicional: false)->precoFinal(),
                'precoOriginal' => (float) $p->produto_preco_venda,
                'temRelampago' => $this->pedidoId && app(PrecificadorService::class)->promocoesVigentesDoProduto($p->id)->isNotEmpty(),
                'controlaEstoque' => (bool) $p->produto_controla_estoque,
                'saldoEstoque' => (float) $p->produto_saldo_estoque,
                'unidadeEstoque' => $p->produto_unidade_estoque,
            ])
            ->toArray();

        $presel = collect($produtos)->firstWhere('id', $produtoId);

        $this->saboresCategoriaNome = $categoria->categoria_nome;
        $this->saboresCategoriaId = $categoria->id;
        $this->respostas = [];
        $maxSabores = $produtoBase->maxSaboresCardapio();
        $this->saboresOpcoes = $categoria->quantidadesSaboresResolvidas()
            ->filter(fn ($o) => $o->quantidade_sabor_quantidade >= 2 && $o->quantidade_sabor_quantidade <= $maxSabores)
            ->map(fn ($o) => ['quantidade' => $o->quantidade_sabor_quantidade, 'descricao' => $o->quantidade_sabor_descricao])
            ->values()
            ->all();
        $this->saboresModo = 1;
        $this->saboresProdutos = $produtos;
        $this->saboresSelecionados = $presel ? [$presel] : [];
        $this->saboresModalAberta = true;
        $this->atualizarOfertaSaborUnico();
        $this->atualizarPerguntasSabores();

        if ($presel) {
            $this->js("setTimeout(() => document.getElementById('sabor-item-{$produtoId}')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 320)");
        }
    }

    public function setModoSabores(int $modo): void
    {
        if ($modo !== 1 && ! collect($this->saboresOpcoes)->contains('quantidade', $modo)) {
            return;
        }

        $this->saboresModo = $modo;
        $this->saboresSelecionados = [];
        $this->atualizarOfertaSaborUnico();
        $this->atualizarPerguntasSabores();
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
        $this->atualizarPerguntasSabores();
    }

    /**
     * Pizza inteira (um sabor) responde as perguntas do produto (e da
     * categoria); pizza de sabores, só as da categoria — mesma regra do
     * servidor. Respostas de perguntas que saíram da tela são descartadas.
     */
    protected function atualizarPerguntasSabores(): void
    {
        $perguntas = $this->saboresModo === 1 && count($this->saboresSelecionados) === 1
            ? Produto::with('categoria')->find($this->saboresSelecionados[0]['id'])?->perguntasAplicaveis()
            : Categoria::find($this->saboresCategoriaId)?->perguntasAplicaveis();

        $this->perguntasDisponiveis = ($perguntas ?? collect())->map->paraTela()->values()->all();
        $this->respostas = array_intersect_key($this->respostas, array_flip(array_column($this->perguntasDisponiveis, 'id')));
    }

    /**
     * Marca/desmarca uma opção: escolha única troca a opção; múltipla escolhe
     * até o máximo da pergunta.
     */
    public function alternarResposta(int $perguntaId, int $opcaoId): void
    {
        $pergunta = collect($this->perguntasDisponiveis)->firstWhere('id', $perguntaId);

        if (! $pergunta || ! collect($pergunta['opcoes'])->contains('id', $opcaoId)) {
            return;
        }

        $marcadas = array_map('intval', $this->respostas[$perguntaId] ?? []);

        $this->respostas[$perguntaId] = match (true) {
            in_array($opcaoId, $marcadas, true) => array_values(array_diff($marcadas, [$opcaoId])),
            $pergunta['maximo'] <= 1 => [$opcaoId],
            count($marcadas) < $pergunta['maximo'] => [...$marcadas, $opcaoId],
            default => $marcadas,
        };
    }

    /** Acréscimo das opções marcadas, por unidade — prévia do total no modal. */
    public function valorUnitarioRespostas(): float
    {
        return (float) collect($this->perguntasDisponiveis)
            ->flatMap(fn (array $pergunta) => collect($pergunta['opcoes'])
                ->filter(fn (array $opcao) => in_array($opcao['id'], array_map('intval', $this->respostas[$pergunta['id']] ?? []), true)))
            ->sum('valor');
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
        $ids = array_map(fn (array $sabor) => (int) $sabor['id'], $this->saboresSelecionados);

        if (count($ids) !== $this->saboresModo) {
            return;
        }

        $this->erroPromocao = null;
        $this->erroEstoque = null;
        $this->avisoEstoque = null;

        // Pizza inteira (um sabor) é o próprio item, com relâmpago e oferta;
        // dois ou mais sabores viram UMA linha com os sabores congelados,
        // igual ao checkout público (ver LancamentoItemPedidoService). Sem
        // pedido gravado, sem promoção — como no item avulso (confirmarItem).
        try {
            $linha = app(LancamentoItemPedidoService::class)->precificar(new ItemSolicitado(
                produtoId: $ids[0],
                saboresIds: count($ids) > 1 ? $ids : [],
                observacao: $this->saboresObservacao,
                ofertaProdutoId: count($ids) === 1 ? $this->ofertaProdutoEscolhido() : null,
                clienteId: $this->clienteSelecionadoId ?: null,
                respostas: $this->respostas,
            ), CanalLancamentoEnum::SALAO, comPromocoes: (bool) $this->pedidoId);
        } catch (ItemIndisponivelException|ComboSaboresInvalidoException|PerguntaNaoRespondidaException $e) {
            $this->erroPromocao = $e->getMessage();

            return;
        }

        if (! $this->lancar($linha)) {
            return;
        }

        $this->fecharSaboresModal();
        $this->notificarPai();
    }

    public function fecharSaboresModal(): void
    {
        $this->saboresModalAberta = false;
        $this->saboresObservacao = '';
        $this->saboresSelecionados = [];
        $this->saboresProdutos = [];
        $this->clienteSelecionadoId = $this->clientePadraoId;
        $this->ofertasDisponiveis = [];
        $this->ofertaEscolhidaId = null;
        $this->perguntasDisponiveis = [];
        $this->respostas = [];
        $this->saboresCategoriaId = null;
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

        // Sem pedido gravado o preço fica sem promoção (não há onde registrar o
        // consumo) — a mesma regra da grade em selecionarProduto().
        try {
            $linha = app(LancamentoItemPedidoService::class)->precificar(new ItemSolicitado(
                produtoId: (int) $this->produtoSelecionadoId,
                quantidade: $this->quantidade,
                adicionaisIds: array_map('intval', $this->adicionaisSelecionados),
                observacao: $this->observacao,
                ofertaProdutoId: $this->ofertaProdutoEscolhido(),
                clienteId: $this->clienteSelecionadoId ?: null,
                respostas: $this->respostas,
            ), CanalLancamentoEnum::SALAO, comPromocoes: (bool) $this->pedidoId);
        } catch (ItemIndisponivelException|PerguntaNaoRespondidaException $e) {
            $this->erroPromocao = $e->getMessage();

            return;
        }

        if (! $this->lancar($linha)) {
            return;
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
        $this->clienteSelecionadoId = $this->clientePadraoId;
        $this->ofertasDisponiveis = [];
        $this->ofertaEscolhidaId = null;
        $this->perguntasDisponiveis = [];
        $this->respostas = [];
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

            // Pizza de sabores é uma linha com quantidade inteira (nº de pizzas),
            // então cai no denominador 1. As frações abaixo só sobrevivem para
            // linhas legadas gravadas antes da linha única de sabores.
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

            $lancamento = app(LancamentoItemPedidoService::class);

            if ($this->pedidoId && is_numeric($itemId)) {
                if ($gravado = ItensPedido::find($itemId)) {
                    $lancamento->atualizarQuantidade($gravado, $novaQtd);
                    $item = $this->itemParaLista($gravado->load(['produto.categoria', 'adicionaisItemPedido.adicional']));
                }

                break;
            }

            // Prévia: os adicionais acompanham a quantidade, como no item gravado.
            $item['quantidade'] = $novaQtd;
            $item['desconto'] = round(($item['desconto_unit'] ?? 0) * $novaQtd, 2);
            $item['adicionais_valor'] = $lancamento->valorDosAdicionais($item['adicionais'] ?? [], $novaQtd);
            $item['valor'] = round(($item['valor_unitario'] * $novaQtd) - $item['desconto'] + $item['adicionais_valor'], 2);
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

        // Pizza de sabores: só os adicionais vinculados a TODOS os sabores
        // (mesma regra do RazelFood) — borda de chocolate não vale para a
        // metade calabresa.
        $produtoIds = ! empty($item['multi_sabor'])
            ? array_column($item['sabores'] ?? [], 'produto_id')
            : [$item['produto_id']];

        $this->editAdicionaisDisponiveis = Produto::with('ap_produto_id.adicional')
            ->whereIn('id', $produtoIds)
            ->get()
            ->map(fn (Produto $produto) => $produto->ap_produto_id
                ->filter(fn ($ap) => $ap->adicional !== null)
                ->mapWithKeys(fn ($ap) => [$ap->adicional->id => [
                    'id' => $ap->adicional->id,
                    'nome' => $ap->adicional->adicional_nome,
                    'valor' => (float) $ap->adicional->adicional_valor,
                ]]))
            ->reduce(fn ($comuns, $doProduto) => $comuns === null ? $doProduto : $comuns->intersectByKeys($doProduto))
            ?->values()
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

        $lancamento = app(LancamentoItemPedidoService::class);
        $adicionaisIds = array_map('intval', $this->editAdicionaisSelecionados);

        foreach ($this->itens as &$item) {
            if ((string) $item['id'] !== (string) $itemId) {
                continue;
            }

            if ($this->pedidoId && is_numeric($itemId)) {
                if ($gravado = ItensPedido::find($itemId)) {
                    // Preço congelado da linha (inclusive a fração de sabor):
                    // só os adicionais mudam.
                    $lancamento->trocarAdicionais($gravado, $adicionaisIds);
                    $gravado->update([
                        'item_pedido_observacao' => $this->editObservacao ?: null,
                        'item_pedido_cliente_id' => $this->editClienteId ?: null,
                    ]);
                    $item = $this->itemParaLista($gravado->load(['produto.categoria', 'adicionaisItemPedido.adicional']));
                }

                break;
            }

            // Prévia: mantém o valor base e troca só os adicionais.
            $adicionais = array_values(array_filter(
                $this->editAdicionaisDisponiveis,
                fn (array $adicional) => in_array((int) $adicional['id'], $adicionaisIds, true),
            ));
            $valorAdicionais = $lancamento->valorDosAdicionais($adicionais, (float) $item['quantidade']);
            $baseSemAdicionais = round((float) $item['valor'] - (float) ($item['adicionais_valor'] ?? 0), 2);

            $item['observacao'] = $this->editObservacao;
            $item['adicionais'] = $adicionais;
            $item['adicionais_valor'] = $valorAdicionais;
            $item['valor'] = round($baseSemAdicionais + $valorAdicionais, 2);
            $item['cliente_id'] = $this->editClienteId ?: null;
            $item['cliente_nome'] = $this->nomeDoCliente($this->editClienteId);
            break;
        }
        unset($item);

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
