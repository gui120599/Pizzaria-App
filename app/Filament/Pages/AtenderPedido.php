<?php

namespace App\Filament\Pages;

use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Filament\Support\PedidoStatusActions;
use App\Livewire\PedidoProdutoSelector;
use App\Models\AdicionaisItemPedido;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Services\ClienteResolverService;
use App\Services\EstoqueService;
use App\Services\Stone\StoneRecebimentoService;
use App\Support\TotaisPedido;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use UnitEnum;

/**
 * Tela unificada de criar/editar Pedido, substituindo o Wizard/Sections do
 * PedidoResource — mesmo padrão já usado em OperarVenda: Page Livewire pura
 * orquestrando componentes "burros" (PedidoProdutoSelector, ClientePicker,
 * EntregaPagamentoPicker) via eventos nomeados, sem Schema/Form Builder.
 *
 * O split de pagamento (EntregaPagamentoPicker) é dado COMBINADO/informativo
 * — grava em pagamentos_pedidos, nunca cria Venda nem mexe em
 * SessaoCaixa/MovimentacoesSessaoCaixa. O recebimento de verdade continua
 * acontecendo só via OperarVenda.
 */
class AtenderPedido extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Atender Pedido';

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 21;

    protected static ?string $title = 'Pedido';

    protected static ?string $slug = 'pedidos/atender/{pedido?}';

    protected string $view = 'filament.pages.atender-pedido';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $pedidoId = null;

    /** @var array<int, array<string, mixed>> */
    public array $itensCarrinho = [];

    /** @var array<string, mixed> */
    public array $clienteData = [];

    /** @var array<string, mixed> */
    public array $entregaPagamentoData = ['opcaoEntregaId' => null, 'pagamentos' => []];

    public string $observacao = '';

    public ?string $statusSelecionado = null;

    public bool $somenteLeitura = false;

    // ── Cobrança na maquininha Stone (recebimento direto no pedido) ────────
    public bool $modalStoneAberta = false;

    public ?int $stoneMaquininhaId = null;

    public ?int $stoneOpcaoPagamentoId = null;

    /** aguardando|pago|erro|cancelado */
    public string $stoneStatusModal = 'aguardando';

    public ?string $stoneErroModal = null;

    public ?int $stonePedidoId = null;

    /**
     * Status a partir dos quais o conteúdo do pedido não faz mais sentido
     * editar. Mais restritivo que StatusPedidoEnum::ehEditavelQuantoAoConteudo()
     * de propósito: aqui a tela inteira vira leitura, porque mexer no conteúdo
     * de um pedido já entregue exigiria também desfazer venda e estoque.
     */
    private const STATUS_SOMENTE_LEITURA = ['ENTREGUE', 'FINALIZADO', 'CANCELADO'];

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            PedidoStatusActions::copiarLinkAcompanhamento()
                ->visible(fn (): bool => $this->pedidoId !== null)
                ->alpineClickHandler(fn (): string => PedidoStatusActions::jsCopiarLink(route('pedido.acompanhar', $this->pedidoId))),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) (Auth::user()?->can('create:pedido') || Auth::user()?->can('update:pedido'));
    }

    public function mount(?Pedido $pedido = null): void
    {
        // Última maquininha usada por este operador — reduz o risco de
        // mandar a cobrança pro aparelho errado (ver Maquininha::scopeStoneDisponivel).
        $this->stoneMaquininhaId = session('stone_ultima_maquininha_id.'.Auth::id());

        if (! $pedido?->exists) {
            return;
        }

        abort_unless(Auth::user()?->can('update', $pedido), 403);

        $pedido->loadMissing(['cliente', 'opcaoEntrega', 'pagamentosCombinados']);

        $this->pedidoId = $pedido->id;
        $this->statusSelecionado = $pedido->pedido_status;
        $this->observacao = (string) ($pedido->pedido_observacao_pagamento ?? '');
        $this->somenteLeitura = in_array($pedido->pedido_status, self::STATUS_SOMENTE_LEITURA, true);

        $this->itensCarrinho = $this->carregarItensDoBanco($pedido->id);

        $cliente = $pedido->pedido_cliente_id ? $pedido->cliente : null;
        $this->clienteData = [
            'clienteId' => $cliente?->id,
            'celular' => $cliente?->cliente_celular ?? '',
            'nome' => $cliente?->cliente_nome ?? '',
            'enderecoRua' => $cliente?->cliente_endereco ?? '',
            'enderecoNumero' => $cliente?->cliente_numero_endereco ?? '',
            'enderecoBairro' => $cliente?->cliente_bairro ?? '',
            'enderecoCidade' => $cliente?->cliente_cidade ?? '',
            'enderecoUf' => $cliente?->cliente_uf_estado ?? '',
            'enderecoCep' => $cliente?->cliente_cep ?? '',
            'semCliente' => $pedido->pedido_cliente_id === null,
            'requerEndereco' => (bool) $pedido->opcaoEntrega?->opcaoentrega_requer_endereco,
        ];

        $this->entregaPagamentoData = [
            'opcaoEntregaId' => $pedido->pedido_opcaoentrega_id,
            'pagamentos' => $pedido->pagamentosCombinados->map(fn (PagamentosPedido $p): array => [
                'opcaoPagamentoId' => $p->pg_pedido_opcaopagamento_id,
                'valor' => number_format((float) $p->pg_pedido_valor, 2, ',', '.'),
                'trocoPara' => $p->pg_pedido_valor_troco_para !== null ? number_format((float) $p->pg_pedido_valor_troco_para, 2, ',', '.') : null,
            ])->all(),
        ];
    }

    /**
     * Mesmo shape que PedidoProdutoSelector::carregarItensDB() — a section de
     * Carrinho no topo desta página (acima do Cliente, espelhando o
     * AttendOrder do razelfood) reaproveita o partial de linha de item, que
     * espera produto_nome/produto_foto/categoria_nome/id já resolvidos.
     */
    private function carregarItensDoBanco(int $pedidoId): array
    {
        return ItensPedido::where('item_pedido_pedido_id', $pedidoId)
            ->where('item_pedido_status', 'INSERIDO')
            ->with(['produto.categoria', 'adicionaisItemPedido.adicional'])
            ->get()
            ->map(fn (ItensPedido $item): array => [
                'id' => $item->id,
                'produto_id' => $item->item_pedido_produto_id,
                'produto_nome' => $item->nomeProduto(),
                'categoria_nome' => $item->produto?->categoria?->categoria_nome ?? '',
                'produto_foto' => $item->produto?->getImagemUrl(),
                'sabores_linhas' => $item->linhasSabores(),
                'cliente_nome' => null,
                'quantidade' => (float) $item->item_pedido_quantidade,
                'valor_unitario' => (float) $item->item_pedido_valor_unitario,
                'desconto_unit' => $item->item_pedido_quantidade > 0
                    ? round((float) $item->item_pedido_desconto / (float) $item->item_pedido_quantidade, 4)
                    : 0,
                'desconto' => (float) $item->item_pedido_desconto,
                'valor' => (float) $item->item_pedido_valor,
                'adicionais_valor' => (float) $item->item_pedido_valor_adicionais,
                'observacao' => $item->item_pedido_observacao ?? '',
                'promocao_id' => $item->item_pedido_promocao_id,
                'adicionais' => $item->adicionaisItemPedido->map(fn (AdicionaisItemPedido $aip): array => [
                    'id' => $aip->aip_adicional_id,
                    'nome' => $aip->adicional?->adicional_nome ?? '—',
                    'valor' => (float) $aip->aip_valor_unitario,
                ])->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Repasses do carrinho no topo da página pro PedidoProdutoSelector, que
     * continua sendo o dono de verdade do estado/regra de negócio dos itens
     * (sabores, adicionais, promoção, estoque). Mesma ideia do AttendOrder do
     * razelfood, mas lá o Page é quem guarda o carrinho — aqui só repassamos
     * pro componente-filho via evento nomeado, mantendo o desenho já
     * existente de "componentes burros conversam só por evento".
     */
    public function incrementarQtd(string $itemId): void
    {
        $this->dispatch('pedido-incrementar-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    public function decrementarQtd(string $itemId): void
    {
        $this->dispatch('pedido-decrementar-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    public function removerItem(string $itemId): void
    {
        $this->dispatch('pedido-remover-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    public function abrirEditModal(string $itemId): void
    {
        $this->dispatch('pedido-abrir-edicao-item', itemId: $itemId)->to(PedidoProdutoSelector::class);
    }

    #[On('itens-pedido-atualizados')]
    public function onItensAtualizados(array $itens): void
    {
        $this->itensCarrinho = $itens;
        unset($this->resumoTotais);
    }

    #[On('pedido-cliente-atualizado')]
    public function onClienteAtualizado(array $dados): void
    {
        $this->clienteData = $dados;
    }

    #[On('pedido-entrega-pagamento-atualizado')]
    public function onEntregaPagamentoAtualizado(array $dados): void
    {
        $this->entregaPagamentoData = $dados;
        unset($this->resumoTotais);
    }

    /**
     * Fonte única do resumo financeiro exibido na tela — itens (bruto),
     * desconto, frete e total, via TotaisPedido::paraItens(). Nunca confia no
     * valor cru salvo no carrinho: recalcula a partir das linhas atuais.
     *
     * @return array{itens: float, desconto: float, frete: float, total: float}
     */
    #[Computed]
    public function resumoTotais(): array
    {
        $opcao = $this->entregaPagamentoData['opcaoEntregaId'] ?? null
            ? OpcoesEntregas::find($this->entregaPagamentoData['opcaoEntregaId'])
            : null;

        return TotaisPedido::paraItens(
            collect($this->itensCarrinho)->map(fn (array $item): ItensPedido => new ItensPedido([
                'item_pedido_valor' => $item['valor'] ?? 0,
                'item_pedido_desconto' => $item['desconto'] ?? 0,
            ])),
            $opcao,
        );
    }

    #[Computed]
    public function totalPreview(): float
    {
        return $this->resumoTotais()['total'];
    }

    public function save(): void
    {
        if ($this->somenteLeitura) {
            return;
        }

        if (empty($this->itensCarrinho)) {
            $this->notificarErro('Adicione ao menos um item ao pedido.');

            return;
        }

        $semCliente = (bool) ($this->clienteData['semCliente'] ?? false);
        $temClienteJaVinculado = filled($this->clienteData['clienteId'] ?? null);

        // Telefone só é obrigatório pra ACHAR/CRIAR um cliente novo — um cliente já
        // vinculado (selecionado na busca, ou já era o cliente do pedido em edição)
        // pode não ter celular cadastrado; exigimos só o nome nesse caso.
        if (! $semCliente && blank($this->clienteData['nome'] ?? null)) {
            $this->notificarErro('Informe o nome do cliente, ou marque "Pedido sem cliente".');

            return;
        }

        if (! $semCliente && ! $temClienteJaVinculado && blank($this->clienteData['celular'] ?? null)) {
            $this->notificarErro('Informe o telefone do cliente (ou busque um já cadastrado), ou marque "Pedido sem cliente".');

            return;
        }

        $pagamentos = $this->entregaPagamentoData['pagamentos'] ?? [];
        if (empty($pagamentos) || collect($pagamentos)->contains(fn (array $p): bool => blank($p['opcaoPagamentoId'] ?? null) || blank($p['valor'] ?? null))) {
            $this->notificarErro('Escolha ao menos uma forma de pagamento e informe o valor de cada uma.');

            return;
        }

        $total = $this->totalPreview;
        $somaPagamentos = collect($pagamentos)->sum(fn (array $p): float => $this->parseValor($p['valor']));
        if (abs($somaPagamentos - $total) > 0.01) {
            $this->notificarErro(sprintf(
                'A soma das formas de pagamento (R$ %s) precisa bater com o total do pedido (R$ %s).',
                number_format($somaPagamentos, 2, ',', '.'),
                number_format($total, 2, ',', '.'),
            ));

            return;
        }

        $opcaoEntrega = ($this->entregaPagamentoData['opcaoEntregaId'] ?? null)
            ? OpcoesEntregas::find($this->entregaPagamentoData['opcaoEntregaId'])
            : null;

        if ($opcaoEntrega?->opcaoentrega_requer_endereco
            && (blank($this->clienteData['enderecoRua'] ?? null) || blank($this->clienteData['enderecoBairro'] ?? null))) {
            $this->notificarErro('Esta opção de entrega exige rua e bairro preenchidos.');

            return;
        }

        $bloqueios = $this->verificarEstoque();
        if ($bloqueios !== []) {
            $this->notificarErro(implode(' | ', $bloqueios), titulo: 'Estoque insuficiente');

            return;
        }

        DB::transaction(function () use ($semCliente, $opcaoEntrega, $pagamentos): void {
            $clienteId = $semCliente ? null : $this->resolverCliente();

            $pedido = $this->pedidoId ? Pedido::findOrFail($this->pedidoId) : new Pedido;

            $souNovo = ! $pedido->exists;

            $dadosPedido = [
                'pedido_cliente_id' => $clienteId,
                'pedido_opcaoentrega_id' => $opcaoEntrega?->id,
                'pedido_observacao_pagamento' => $this->observacao !== '' ? $this->observacao : null,
                'pedido_endereco_entrega' => $this->montarEnderecoSnapshot(),
            ];

            if ($souNovo) {
                // ABERTO, não INICIADO: um pedido montado aqui pelo atendente já está
                // confirmado por definição. INICIADO é o estado "aguardando confirmação
                // da loja" do cardápio público (ver ConfirmacoesPedidos) — pedidos nesse
                // status ficam invisíveis pro resto do sistema (OperarVenda, dashboards,
                // PDFController) e sujeitos ao cancelamento em massa de
                // pedidos:zerar-iniciados.
                $dadosPedido['pedido_status'] = 'ABERTO';
                $dadosPedido['pedido_usuario_garcom_id'] = Auth::id();
                $dadosPedido['pedido_origem'] = PedidoOrigemEnum::ATENDENTE;
            } else {
                $dadosPedido['pedido_status'] = $this->statusSelecionado ?? $pedido->pedido_status;
            }

            $pedido->fill($dadosPedido);
            $pedido->save();

            $this->sincronizarItens($pedido, $souNovo);

            $linhas = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->get();

            $totais = TotaisPedido::paraItens($linhas, $opcaoEntrega);

            $pedido->update([
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_frete' => $totais['frete'],
                'pedido_valor_total' => $totais['total'],
            ]);

            $this->sincronizarPagamentos($pedido, $pagamentos);

            $this->pedidoId = $pedido->id;
        });

        // Salvou, a tela volta em branco para o próximo atendimento — o
        // balcão lança um pedido atrás do outro. O link da notificação reabre
        // o que acabou de ser salvo (ex.: para cobrar na maquininha).
        Notification::make()
            ->title("Pedido #{$this->pedidoId} salvo")
            ->success()
            ->actions([
                Action::make('abrirPedido')
                    ->label('Abrir pedido')
                    ->url(static::getUrl(['pedido' => $this->pedidoId])),
            ])
            ->send();

        $this->redirect(static::getUrl());
    }

    private function verificarEstoque(): array
    {
        $bloqueios = [];
        $estoque = app(EstoqueService::class);

        foreach (collect($this->itensCarrinho)->groupBy('produto_id') as $produtoId => $doGrupo) {
            $produto = Produto::find($produtoId);
            if (! $produto) {
                continue;
            }

            $qtdTotal = (float) collect($doGrupo)->sum('quantidade');
            $resultado = $estoque->checarDisponibilidade($produto, $qtdTotal);
            array_push($bloqueios, ...$resultado['bloqueios']);
        }

        return $bloqueios;
    }

    private function resolverCliente(): ?int
    {
        $nome = trim((string) ($this->clienteData['nome'] ?? ''));
        $clienteIdSelecionado = $this->clienteData['clienteId'] ?? null;

        // Cliente já identificado (por id, seja via busca manual ou já vinculado ao
        // pedido em edição): só atualiza o nome, nunca mexe no endereço do cadastro
        // — o endereço desta tela vira só o snapshot pedido_endereco_entrega.
        if ($clienteIdSelecionado) {
            $cliente = Cliente::find($clienteIdSelecionado);
            if ($cliente) {
                if ($nome !== '') {
                    $cliente->update(['cliente_nome' => $nome]);
                }

                return $cliente->id;
            }
        }

        if ($nome === '') {
            return null;
        }

        $cliente = app(ClienteResolverService::class)->resolverOuCriar([
            'nome' => $nome,
            'celular' => $this->clienteData['celular'] ?? null,
            'endereco' => $this->clienteData['enderecoRua'] ?? null,
            'numero_endereco' => $this->clienteData['enderecoNumero'] ?? null,
            'bairro' => $this->clienteData['enderecoBairro'] ?? null,
            'cidade' => $this->clienteData['enderecoCidade'] ?? null,
            'uf_estado' => $this->clienteData['enderecoUf'] ?? null,
            'cep' => $this->clienteData['enderecoCep'] ?? null,
        ]);

        return $cliente->id;
    }

    private function montarEnderecoSnapshot(): ?string
    {
        $partes = array_filter([
            $this->clienteData['enderecoRua'] ?? null,
            $this->clienteData['enderecoNumero'] ?? null,
            $this->clienteData['enderecoBairro'] ?? null,
            $this->clienteData['enderecoCidade'] ?? null,
        ], fn ($v) => filled($v));

        return $partes !== [] ? implode(', ', $partes) : null;
    }

    private function sincronizarItens(Pedido $pedido, bool $souNovo): void
    {
        if (! $souNovo) {
            // Em edição, o PedidoProdutoSelector já persiste cada ação direto no
            // banco (modo dual, ver docblock do componente) — só relemos depois.
            return;
        }

        foreach ($this->itensCarrinho as $item) {
            $itemModel = ItensPedido::create([
                'item_pedido_pedido_id' => $pedido->id,
                'item_pedido_produto_id' => $item['produto_id'],
                'item_pedido_quantidade' => $item['quantidade'],
                'item_pedido_valor_unitario' => $item['valor_unitario'],
                'item_pedido_valor' => $item['valor'],
                'item_pedido_desconto' => $item['desconto'] ?? 0,
                'item_pedido_valor_adicionais' => $item['adicionais_valor'] ?? 0,
                'item_pedido_desconto_unitario' => $item['desconto_unit'] ?? null,
                'item_pedido_observacao' => ($item['observacao'] ?? '') !== '' ? $item['observacao'] : null,
                // Pizza de sabores: uma linha com os sabores congelados.
                'item_pedido_sabores' => $item['sabores'] ?? null,
                'item_pedido_status' => 'INSERIDO',
            ]);

            foreach ($item['adicionais'] ?? [] as $adicional) {
                AdicionaisItemPedido::create([
                    'aip_item_pedido_id' => $itemModel->id,
                    'aip_adicional_id' => $adicional['id'],
                    'aip_quantidade' => 1,
                    'aip_valor_unitario' => $adicional['valor'],
                    'aip_valor_total' => $adicional['valor'],
                ]);
            }
        }
    }

    private function sincronizarPagamentos(Pedido $pedido, array $pagamentos): void
    {
        $pedido->pagamentosCombinados()->delete();
        $nomes = [];

        foreach (array_values($pagamentos) as $ordem => $linha) {
            $opcao = ($linha['opcaoPagamentoId'] ?? null) ? OpcoesPagamento::find($linha['opcaoPagamentoId']) : null;
            $nomes[] = $opcao?->opcaopag_nome;

            PagamentosPedido::create([
                'pg_pedido_pedido_id' => $pedido->id,
                'pg_pedido_opcaopagamento_id' => $opcao?->id,
                'pg_pedido_opcaopagamento_nome' => $opcao?->opcaopag_nome,
                'pg_pedido_valor' => $this->parseValor($linha['valor'] ?? '0'),
                'pg_pedido_valor_troco_para' => filled($linha['trocoPara'] ?? null) ? $this->parseValor($linha['trocoPara']) : null,
                'pg_pedido_ordem' => $ordem,
            ]);
        }

        // Texto legado, no mesmo formato da tela antiga ("Pix, Dinheiro"):
        // ainda é o que a sugestão de pagamento do PDV (OperarVenda) e as
        // telas legadas leem.
        $pedido->update(['pedido_descricao_pagamento' => implode(', ', array_filter($nomes)) ?: null]);
    }

    private function parseValor(mixed $valor): float
    {
        $digitos = (int) str_replace(['.', ','], '', (string) ($valor ?? '0'));

        return $digitos / 100;
    }

    private function notificarErro(string $mensagem, string $titulo = 'Não foi possível salvar'): void
    {
        Notification::make()
            ->title($titulo)
            ->body($mensagem)
            ->danger()
            ->send();
    }

    /**
     * Opções do Select de status — sem CANCELADO de propósito: cancelar exige o
     * caminho dedicado, com motivo obrigatório e PedidoPolicy::cancel.
     *
     * @return array<string, string>
     */
    public function getOpcoesStatus(): array
    {
        return collect(StatusPedidoEnum::paraSelect())
            ->forget(StatusPedidoEnum::CANCELADO->value)
            ->all();
    }

    /** Maquininhas Stone com número de série — mesmo critério do PDV (OperarVenda). */
    #[Computed]
    public function maquininhasStone()
    {
        return Maquininha::stoneDisponivel()->orderBy('nome')->pluck('nome', 'id');
    }

    #[Computed]
    public function opcoesPagamentoStone()
    {
        return OpcoesPagamento::where('opcaopag_stone_integrada', true)->orderBy('opcaopag_nome')->pluck('opcaopag_nome', 'id');
    }

    #[Computed]
    public function podeCobrarStone(): bool
    {
        return ! $this->somenteLeitura
            && $this->maquininhasStone->isNotEmpty()
            && $this->pedidoTemCombinadoStone();
    }

    private function pedidoTemCombinadoStone(): bool
    {
        return (bool) ($this->pedidoId ? Pedido::find($this->pedidoId)?->temPagamentoCombinadoStone() : false);
    }

    public function abrirModalStone(): void
    {
        if (! $this->pedidoId) {
            $this->notificarErro('Salve o pedido antes de cobrar na maquininha.');

            return;
        }

        if (! $this->pedidoTemCombinadoStone()) {
            $this->notificarErro('Nenhuma forma de pagamento combinada deste pedido está integrada à Stone.');

            return;
        }

        $this->modalStoneAberta = true;
        $this->stoneStatusModal = 'form';
        $this->stoneErroModal = null;
        $this->stonePedidoId = null;
    }

    public function fecharModalStone(): void
    {
        $recemPago = $this->stoneStatusModal === 'pago';

        $this->modalStoneAberta = false;
        $this->stonePedidoId = null;
        $this->stoneErroModal = null;
        $this->stoneStatusModal = 'form';

        // O pagamento integral finaliza o pedido pelo webhook — recarrega a
        // página pra refletir o novo status (a mesma UX de OperarVenda).
        if ($recemPago) {
            $this->redirect(static::getUrl(['pedido' => $this->pedidoId]));
        }
    }

    public function enviarCobrancaStone(): void
    {
        if (! $this->stoneMaquininhaId) {
            $this->addError('stoneMaquininhaId', 'Selecione a maquininha.');

            return;
        }

        $maquininha = Maquininha::find($this->stoneMaquininhaId);
        $pedido = Pedido::find($this->pedidoId);

        if (! $maquininha || ! $pedido) {
            $this->notificarErro('Pedido ou maquininha não encontrado.');

            return;
        }

        if (! $pedido->temPagamentoCombinadoStone()) {
            $this->notificarErro('Nenhuma forma de pagamento combinada deste pedido está integrada à Stone.');

            return;
        }

        session(['stone_ultima_maquininha_id.'.Auth::id() => $this->stoneMaquininhaId]);

        $opcao = $this->stoneOpcaoPagamentoId ? OpcoesPagamento::find($this->stoneOpcaoPagamentoId) : null;
        $modo = $opcao ? StonePedidoModo::Direto : StonePedidoModo::Listado;

        try {
            $stonePedido = app(StoneRecebimentoService::class)->iniciarCobrancaDePedido(
                $pedido, $opcao, $maquininha, $this->totalPreview, $modo,
            );
            $this->stonePedidoId = $stonePedido->id;
            $this->stoneStatusModal = 'aguardando';
        } catch (StoneConnectException $e) {
            $this->stoneStatusModal = 'erro';
            $this->stoneErroModal = $e->getMessage();
        }
    }

    public function verificarStatusStone(): void
    {
        if (! $this->stonePedidoId) {
            return;
        }

        $pedido = StonePedido::find($this->stonePedidoId);
        if (! $pedido) {
            return;
        }

        $this->stoneStatusModal = match ($pedido->stp_status) {
            StonePedidoStatus::Pago => 'pago',
            StonePedidoStatus::Cancelado, StonePedidoStatus::Estornado => 'cancelado',
            StonePedidoStatus::Falha => 'erro',
            default => $this->stoneStatusModal,
        };

        if ($this->stoneStatusModal === 'erro' && $this->stoneErroModal === null) {
            $this->stoneErroModal = 'A cobrança falhou na Stone. Tente novamente.';
        }
    }

    public function cancelarCobrancaStone(): void
    {
        if (! $this->stonePedidoId) {
            return;
        }

        $stonePedido = StonePedido::find($this->stonePedidoId);
        if ($stonePedido) {
            app(StoneRecebimentoService::class)->cancelarCobranca($stonePedido);
        }

        $this->fecharModalStone();
    }
}
