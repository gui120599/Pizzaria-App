<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Filament\Concerns\AutorizaComPinDeGerente;
use App\Filament\Garcom\Concerns\CarrinhoLateral;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\MesaCliente\AprovacaoPedidoMesaService;
use App\Services\MesaCliente\MesaChamadoService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\PedidosSessaoMesaService;
use App\Support\ContaMesa;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * Atendimento de uma conta de mesa/comanda: lançar a rodada, acompanhar as
 * rodadas enviadas e fechar a conta (pré-conta, taxa, Stone). Também decide o
 * que chegou pelo QR da mesa: aprovar, editar ou recusar o pedido do cliente,
 * atender chamados e bloquear celular.
 */
class AtenderMesa extends Page
{
    use AutorizaComPinDeGerente;
    use CarrinhoLateral;

    protected string $view = 'filament.garcom.pages.atender-mesa';

    protected static ?string $slug = 'mesa/{sessao}';

    protected static bool $shouldRegisterNavigation = false;

    public int $sessaoId = 0;

    public int $rascunhoId = 0;

    #[Url]
    public string $aba = 'pedir';

    /** Painel lateral no desktop: 'rodada' (carrinho) | 'rodadas' | 'conta'. */
    public string $abaPainel = 'rodada';

    /** @var array<int, int> */
    public array $prontasAvisadas = [];

    /** "Lançando para": pessoa da mesa que recebe os próximos itens (null = mesa geral). */
    public ?int $clientePadraoId = null;

    /** Pedido do cliente (QR) aberto no seletor para o garçom ajustar antes de aprovar. */
    public ?int $editandoPedidoId = null;

    /**
     * Pedidos do QR e chamados desta mesa já avisados neste aparelho.
     *
     * @var array<int, string>
     */
    public array $avisosMesa = [];

    public function mount(int|string $sessao): void
    {
        $sessaoMesa = SessaoMesa::find($sessao);

        if (! $sessaoMesa || $sessaoMesa->sessao_mesa_status !== 'ABERTA') {
            $this->voltarAoMapa('Esta conta não está mais aberta.');

            return;
        }

        $this->sessaoId = $sessaoMesa->id;
        $this->rascunhoId = $this->servico()->rascunho($sessaoMesa, $this->usuario())->id;
        $this->prontasAvisadas = $this->rodadasProntasIds();
        $this->avisosMesa = $this->chavesAvisosMesa();
    }

    public function getTitle(): string|Htmlable
    {
        return SessaoMesa::with('mesa')->find($this->sessaoId)?->mesa?->mesa_nome ?? 'Mesa';
    }

    public function sessao(): SessaoMesa
    {
        return SessaoMesa::with(['mesa', 'garcom:id,name,name_first'])->findOrFail($this->sessaoId);
    }

    /** @return array<int, array{id: int, nome: string}> */
    public function clientesDaMesa(): array
    {
        return $this->sessao()->clientes()
            ->with('cliente')
            ->get()
            ->map(fn ($c) => ['id' => $c->smc_cliente_id, 'nome' => $c->cliente?->cliente_nome ?? 'Cliente'])
            ->all();
    }

    /** @return Collection<int, Pedido> Rodadas enviadas, mais recentes primeiro */
    public function rodadas(): Collection
    {
        return Pedido::query()
            ->where('pedido_sessao_mesa_id', $this->sessaoId)
            ->where('pedido_status', '!=', StatusPedidoEnum::INICIADO->value)
            ->with([
                'garcom:id,name,name_first',
                'participante:id,mp_nome',
                'item_pedido_pedido_id' => fn ($q) => $q->where('item_pedido_status', 'INSERIDO'),
                'item_pedido_pedido_id.produto:id,produto_descricao',
                'item_pedido_pedido_id.cliente:id,cliente_nome',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional:id,adicional_nome',
            ])
            ->latest('id')
            ->get();
    }

    /** @return array{subtotal: float, percentual: float, taxa: float, total: float} */
    public function conta(): array
    {
        return ContaMesa::para($this->sessao());
    }

    /** @return array<int, array{nome: string, subtotal: float, taxa: float, total: float}> */
    public function contaPorPessoa(): array
    {
        return ContaMesa::porPessoa($this->sessao());
    }

    public function podeFecharMesa(): bool
    {
        return (bool) $this->usuario()->can('update', $this->sessao());
    }

    // ── Pessoas da mesa ─────────────────────────────────────────────────────

    public function lancarPara(?int $clienteId): void
    {
        $this->clientePadraoId = $clienteId && collect($this->clientesDaMesa())->contains('id', $clienteId)
            ? $clienteId
            : null;
    }

    public function adicionarPessoaAction(): Action
    {
        return Action::make('adicionarPessoa')
            ->modalHeading('Pessoa na mesa')
            ->modalSubmitActionLabel('Adicionar')
            ->modalWidth('sm')
            ->schema([
                ToggleButtons::make('modo')
                    ->hiddenLabel()
                    ->options(['buscar' => 'Buscar', 'cadastrar' => 'Cadastrar', 'livre' => 'Só o nome'])
                    ->default('buscar')
                    ->inline()
                    ->live(),
                Select::make('cliente_id')
                    ->label('Cliente')
                    ->placeholder('Digite nome ou celular')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $busca): array {
                        $digitos = preg_replace('/\D/', '', $busca);

                        return Cliente::query()
                            ->naoAvulsos()
                            ->where(fn ($q) => $q
                                ->where('cliente_nome', 'like', "%{$busca}%")
                                ->when(strlen($digitos) >= 4, fn ($qq) => $qq->orWhere('cliente_celular', 'like', "%{$digitos}%")))
                            ->orderBy('cliente_nome')
                            ->limit(20)
                            ->get(['id', 'cliente_nome', 'cliente_celular'])
                            ->mapWithKeys(fn (Cliente $c) => [$c->id => trim($c->cliente_nome.' '.($c->cliente_celular ? '· '.$c->cliente_celular : ''))])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => Cliente::find($value)?->cliente_nome)
                    ->visible(fn (Get $get): bool => $get('modo') === 'buscar')
                    ->required(fn (Get $get): bool => $get('modo') === 'buscar'),
                TextInput::make('nome')
                    ->label(fn (Get $get): string => $get('modo') === 'livre' ? 'Nome (não cria cadastro)' : 'Nome')
                    ->maxLength(120)
                    ->visible(fn (Get $get): bool => $get('modo') !== 'buscar')
                    ->required(fn (Get $get): bool => $get('modo') !== 'buscar'),
                TextInput::make('celular')
                    ->label('Celular (opcional)')
                    ->tel()
                    ->mask('(99) 99999-9999')
                    ->visible(fn (Get $get): bool => $get('modo') === 'cadastrar'),
            ])
            ->action(function (array $data): void {
                $this->executarAutorizado(function () use ($data) {
                    $cliente = $this->servico()->adicionarClienteNaMesa(
                        $this->sessao(),
                        ($data['modo'] ?? 'buscar') === 'buscar' ? (int) $data['cliente_id'] : null,
                        $data['nome'] ?? null,
                        $data['celular'] ?? null,
                        ($data['modo'] ?? null) === 'livre',
                    );
                    $this->clientePadraoId = $cliente->id;
                }, 'Pessoa adicionada à mesa.');
            });
    }

    public function removerPessoa(int $clienteId): void
    {
        $this->executarAutorizado(fn () => $this->servico()->removerClienteDaMesa($this->sessao(), $clienteId), 'Pessoa removida da mesa.');

        if ($this->clientePadraoId === $clienteId && ! collect($this->clientesDaMesa())->contains('id', $clienteId)) {
            $this->clientePadraoId = null;
        }
    }

    // ── Pedido pelo QR da mesa ─────────────────────────────────────────────

    /** @return Collection<int, Pedido> Pedidos do cliente esperando o garçom, mais antigos primeiro */
    public function pedidosDoCliente(): Collection
    {
        return $this->queryPendentes()
            ->with([
                'participante:id,mp_nome',
                'item_pedido_pedido_id' => fn ($q) => $q->where('item_pedido_status', 'INSERIDO'),
                'item_pedido_pedido_id.produto:id,produto_descricao',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional:id,adicional_nome',
            ])
            ->oldest('id')
            ->get();
    }

    /** @return Collection<int, MesaChamado> */
    public function chamadosPendentes(): Collection
    {
        return MesaChamado::pendentes()
            ->where('mc_sessao_mesa_id', $this->sessaoId)
            ->with('participante:id,mp_nome')
            ->oldest('id')
            ->get();
    }

    /** @return Collection<int, MesaParticipante> Celulares que entraram na conta pelo QR */
    public function participantes(): Collection
    {
        return MesaParticipante::where('mp_sessao_mesa_id', $this->sessaoId)->orderBy('id')->get();
    }

    public function aprovarPedido(int $pedidoId): void
    {
        $pedido = $this->queryPendentes()->find($pedidoId);

        if (! $pedido) {
            Notification::make()->title('Este pedido já foi decidido.')->warning()->send();

            return;
        }

        try {
            $aprovou = app(AprovacaoPedidoMesaService::class)->aprovar($pedido, $this->usuario());
        } catch (EstoqueInsuficienteException $e) {
            Notification::make()->title('Sem estoque: '.$e->getMessage())->danger()->send();

            return;
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return;
        }

        if ($this->editandoPedidoId === $pedidoId) {
            $this->cancelarEdicao();
        }

        Notification::make()
            ->title($aprovou ? 'Pedido do cliente enviado para a cozinha.' : 'Este pedido já foi decidido.')
            ->success()
            ->send();
    }

    public function recusarPedidoAction(): Action
    {
        return Action::make('recusarPedido')
            ->modalHeading('Recusar o pedido do cliente?')
            ->modalDescription('O cliente vê o motivo no celular. Nada vai para a cozinha nem para a conta.')
            ->modalSubmitActionLabel('Recusar pedido')
            ->color('danger')
            ->modalWidth('sm')
            ->schema([
                TextInput::make('motivo')->label('Motivo')->placeholder('Ex.: item acabou')->required()->maxLength(255),
            ])
            ->action(function (array $data, array $arguments): void {
                $pedido = $this->queryPendentes()->find($arguments['pedido'] ?? null);

                if (! $pedido) {
                    Notification::make()->title('Este pedido já foi decidido.')->warning()->send();

                    return;
                }

                try {
                    app(AprovacaoPedidoMesaService::class)->recusar($pedido, $this->usuario(), $data['motivo']);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->warning()->send();

                    return;
                }

                if ($this->editandoPedidoId === $pedido->id) {
                    $this->cancelarEdicao();
                }

                Notification::make()->title('Pedido recusado.')->success()->send();
            });
    }

    /** Abre o pedido do cliente no seletor; o botão de enviar passa a aprovar. */
    public function editarPedido(int $pedidoId): void
    {
        if (! $this->queryPendentes()->whereKey($pedidoId)->exists()) {
            Notification::make()->title('Este pedido já foi decidido.')->warning()->send();

            return;
        }

        $this->editandoPedidoId = $pedidoId;
        $this->itensCarrinho = [];
        $this->aba = 'pedir';
        $this->abaPainel = 'rodada';
    }

    public function cancelarEdicao(): void
    {
        $this->editandoPedidoId = null;
        $this->itensCarrinho = [];
    }

    public function atenderChamado(int $chamadoId): void
    {
        $chamado = MesaChamado::where('mc_sessao_mesa_id', $this->sessaoId)->find($chamadoId);

        if ($chamado) {
            app(MesaChamadoService::class)->atender($chamado, $this->usuario());
        }

        $this->avisosMesa = $this->chavesAvisosMesa();
    }

    public function bloquearParticipante(int $participanteId): void
    {
        $participante = MesaParticipante::where('mp_sessao_mesa_id', $this->sessaoId)->findOrFail($participanteId);
        app(ParticipanteMesaService::class)->bloquear($participante, $this->usuario());

        Notification::make()->title("{$participante->mp_nome} não pode mais pedir pelo celular nesta mesa.")->success()->send();
    }

    public function desbloquearParticipante(int $participanteId): void
    {
        $participante = MesaParticipante::where('mp_sessao_mesa_id', $this->sessaoId)->findOrFail($participanteId);
        app(ParticipanteMesaService::class)->desbloquear($participante);

        Notification::make()->title("{$participante->mp_nome} pode voltar a pedir pelo celular.")->success()->send();
    }

    // ── Poll ────────────────────────────────────────────────────────────────

    public function atualizar(): void
    {
        $prontas = $this->rodadasProntasIds();
        $novas = array_diff($prontas, $this->prontasAvisadas);
        $this->prontasAvisadas = $prontas;

        if ($novas !== []) {
            Notification::make()->title('Rodada pronta para servir!')->success()->send();
            $this->dispatch('garcom-pedido-pronto');
        }

        $avisos = $this->chavesAvisosMesa();
        $novosAvisos = array_diff($avisos, $this->avisosMesa);
        $this->avisosMesa = $avisos;

        if ($novosAvisos !== []) {
            Notification::make()->title('O cliente chamou pelo celular.')->body('Veja o topo da tela.')->warning()->send();
            $this->dispatch('garcom-pedido-pronto');
        }

        // O pedido em edição foi decidido em outro aparelho: volta à rodada.
        if ($this->editandoPedidoId && ! $this->queryPendentes()->whereKey($this->editandoPedidoId)->exists()) {
            $this->cancelarEdicao();
        }

        if (SessaoMesa::whereKey($this->sessaoId)->value('sessao_mesa_status') !== 'ABERTA') {
            $this->voltarAoMapa('A conta desta mesa foi fechada.');
        }
    }

    // ── Rodada ──────────────────────────────────────────────────────────────

    /** Disparado pelo botão do PedidoProdutoSelector (requestSubmit de #pedido-form). */
    public function enviarRodada(): void
    {
        if ($this->editandoPedidoId) {
            $this->aprovarPedido($this->editandoPedidoId);

            return;
        }

        try {
            $enviada = $this->servico()->enviarRodada(Pedido::findOrFail($this->rascunhoId));
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return;
        }

        Notification::make()
            ->title($enviada ? 'Rodada enviada para a cozinha.' : 'Esta rodada já tinha sido enviada.')
            ->success()
            ->send();

        $this->rascunhoId = $this->servico()->rascunho($this->sessao(), $this->usuario())->id;
        // Celular vai para Rodadas; no desktop o painel volta à rodada nova (vazia).
        $this->aba = 'rodadas';
        $this->abaPainel = 'rodada';
        $this->itensCarrinho = [];
    }

    public function marcarEntregue(int $pedidoId): void
    {
        $pedido = Pedido::where('pedido_sessao_mesa_id', $this->sessaoId)->findOrFail($pedidoId);

        try {
            $this->servico()->marcarEntregue($pedido, $this->usuario());
        } catch (TransicaoPedidoInvalidaException) {
            // Outro aparelho já marcou — o próximo render mostra o status atual.
        }

        $this->prontasAvisadas = $this->rodadasProntasIds();
    }

    public function cancelarItemAction(): Action
    {
        return Action::make('cancelarItem')
            ->modalHeading(fn (array $arguments): string => 'Cancelar '.(ItensPedido::find($arguments['item'] ?? null)?->nomeProduto() ?? 'item'))
            ->modalSubmitActionLabel('Cancelar item')
            ->color('danger')
            ->modalWidth('sm')
            ->schema(fn (): array => [
                TextInput::make('motivo')->label('Motivo')->required()->maxLength(255),
                ...$this->camposAutorizacao(AcaoAutorizadaEnum::CANCELAR_ITEM),
            ])
            ->action(function (array $data, array $arguments): void {
                $item = ItensPedido::whereKey($arguments['item'] ?? null)
                    ->whereHas('pedido', fn ($q) => $q->where('pedido_sessao_mesa_id', $this->sessaoId))
                    ->firstOrFail();

                $this->executarAutorizado(fn () => $this->servico()->cancelarItem(
                    $item, $this->usuario(), $data['autorizador_id'] ?? null, $data['pin'] ?? null, $data['motivo'],
                ), 'Item cancelado.');
            });
    }

    // ── Conta ───────────────────────────────────────────────────────────────

    public function alternarContaSolicitada(): void
    {
        $sessao = $this->sessao();

        $this->executarAutorizado(
            fn () => $this->servico()->solicitarConta($sessao, ! $sessao->contaSolicitada()),
            $sessao->contaSolicitada() ? 'Pedido de conta desfeito.' : 'Conta pedida — a mesa aparece em roxo no mapa.',
        );
    }

    public function alterarPessoasAction(): Action
    {
        return Action::make('alterarPessoas')
            ->label('Pessoas')
            ->modalSubmitActionLabel('Salvar')
            ->modalWidth('sm')
            ->fillForm(fn (): array => ['pessoas' => $this->sessao()->sessao_mesa_pessoas])
            ->schema([
                TextInput::make('pessoas')->label('Pessoas na mesa')->numeric()->integer()->minValue(1)->maxValue(99)->required(),
            ])
            ->action(function (array $data): void {
                $sessao = $this->sessao();

                $this->executarAutorizado(
                    fn () => $this->servico()->alterarPessoas($sessao, (int) $data['pessoas'], $sessao->sessao_mesa_versao),
                    'Número de pessoas atualizado.',
                );
            });
    }

    public function removerTaxaAction(): Action
    {
        return Action::make('removerTaxa')
            ->label('Tirar taxa de serviço')
            ->modalSubmitActionLabel('Tirar taxa')
            ->color('danger')
            ->modalWidth('sm')
            ->schema(fn (): array => [
                TextInput::make('motivo')->label('Motivo')->maxLength(255),
                ...$this->camposAutorizacao(AcaoAutorizadaEnum::REMOVER_TAXA_SERVICO),
            ])
            ->action(function (array $data): void {
                $sessao = $this->sessao();

                $this->executarAutorizado(fn () => $this->servico()->removerTaxaServico(
                    $sessao, $sessao->sessao_mesa_versao, $this->usuario(),
                    $data['autorizador_id'] ?? null, $data['pin'] ?? null, $data['motivo'] ?? null,
                ), 'Taxa de serviço retirada.');
            });
    }

    public function restaurarTaxa(): void
    {
        $sessao = $this->sessao();

        $this->executarAutorizado(
            fn () => $this->servico()->restaurarTaxaServico($sessao, $sessao->sessao_mesa_versao),
            'Taxa de serviço incluída.',
        );
    }

    public function fecharMesaAction(): Action
    {
        return Action::make('fecharMesa')
            ->label('Fechar mesa')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Fechar mesa e liberar para outro cliente?')
            ->modalDescription(function (): string {
                $naCozinha = $this->servico()->rodadasNaoServidas($this->sessao());

                return ($naCozinha > 0 ? "Há {$naCozinha} rodada(s) ainda não servida(s). " : '')
                    .'A conta continua pendente no caixa até ser paga.';
            })
            ->modalSubmitActionLabel('Fechar mesa')
            ->visible(fn (): bool => $this->podeFecharMesa())
            ->action(function (): void {
                try {
                    $this->servico()->fecharSessao($this->sessao(), $this->usuario());
                } catch (RuntimeException|AuthorizationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Mesa liberada. A conta segue pendente no caixa.')->success()->send();
                $this->redirect(MapaMesas::getUrl(), navigate: true);
            });
    }

    public function transferirMesaAction(): Action
    {
        return Action::make('transferirMesa')
            ->label('Transferir')
            ->modalSubmitActionLabel('Transferir conta')
            ->modalWidth('sm')
            ->schema(fn (): array => [
                Select::make('mesa_id')
                    ->label('Para')
                    ->options(fn () => Mesa::where('mesa_status', 'LIBERADA')->orderBy('mesa_tipo')->orderBy('mesa_numero')->orderBy('mesa_nome')->pluck('mesa_nome', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('motivo')->label('Motivo')->maxLength(255),
                ...$this->camposAutorizacao(AcaoAutorizadaEnum::TRANSFERIR_MESA),
            ])
            ->action(function (array $data): void {
                $this->executarAutorizado(fn () => $this->servico()->transferirMesa(
                    $this->sessao(), (int) $data['mesa_id'], $this->usuario(),
                    $data['autorizador_id'] ?? null, $data['pin'] ?? null, $data['motivo'] ?? null,
                ), 'Conta transferida.');
            });
    }

    // ── Pedidos de fora / tirar da mesa ────────────────────────────────────

    /** @return array<int, int> Rodadas que ainda podem sair da conta (sem item no caixa) */
    public function idsRemoviveis(): array
    {
        return app(PedidosSessaoMesaService::class)->queryRemoviveis($this->sessao())->pluck('id')->all();
    }

    public function adicionarPedidoAction(): Action
    {
        return Action::make('adicionarPedido')
            ->label('Trazer pedido existente')
            ->modalHeading('Trazer pedido para esta mesa')
            ->modalDescription('Pedidos ativos do balcão, do cardápio ou de outra mesa, ainda não lançados no caixa.')
            ->modalSubmitActionLabel('Trazer selecionados')
            ->visible(fn (): bool => $this->podeFecharMesa())
            ->schema(function (): array {
                $opcoes = app(PedidosSessaoMesaService::class)->opcoesParaAdicionar($this->sessao(), $this->usuario());

                return [
                    CheckboxList::make('pedidos')
                        ->hiddenLabel()
                        ->options($opcoes['opcoes'])
                        ->descriptions($opcoes['descricoes'])
                        ->searchable()
                        ->noSearchResultsMessage('Nenhum pedido encontrado.')
                        ->required()
                        ->validationMessages(['required' => 'Selecione ao menos um pedido.']),
                ];
            })
            ->action(fn (array $data) => $this->moverPedidos(
                fn (PedidosSessaoMesaService $servico) => $servico->adicionar($this->sessao(), $data['pedidos'], $this->usuario()),
                'trazido(s) para a mesa',
            ));
    }

    public function removerPedidoAction(): Action
    {
        return Action::make('removerPedido')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Tirar a rodada #'.($arguments['pedido'] ?? '').' da mesa?')
            ->modalDescription('Ela sai desta conta e vai para Pedidos avulsos no caixa.')
            ->modalSubmitActionLabel('Tirar da mesa')
            ->visible(fn (): bool => $this->podeFecharMesa())
            ->action(fn (array $arguments) => $this->moverPedidos(
                fn (PedidosSessaoMesaService $servico) => $servico->remover($this->sessao(), [(int) ($arguments['pedido'] ?? 0)], $this->usuario()),
                'tirado(s) da mesa',
            ));
    }

    /** @param  callable(PedidosSessaoMesaService): int  $operacao */
    private function moverPedidos(callable $operacao, string $sufixo): void
    {
        try {
            $quantidade = $operacao(app(PedidosSessaoMesaService::class));
        } catch (RuntimeException|AuthorizationException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title("{$quantidade} pedido(s) {$sufixo}.")->success()->send();
        $this->prontasAvisadas = $this->rodadasProntasIds();
    }

    // ── Apoio ───────────────────────────────────────────────────────────────

    /** Pedidos do QR desta conta esperando o garçom. */
    private function queryPendentes(): Builder
    {
        return Pedido::query()
            ->where('pedido_sessao_mesa_id', $this->sessaoId)
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_aprovacao_status', StatusAprovacaoPedidoEnum::PENDENTE->value);
    }

    /** @return array<int, string> */
    private function chavesAvisosMesa(): array
    {
        return [
            ...$this->queryPendentes()->pluck('id')->map(fn ($id) => 'p'.$id)->all(),
            ...MesaChamado::pendentes()->where('mc_sessao_mesa_id', $this->sessaoId)->pluck('id')->map(fn ($id) => 'c'.$id)->all(),
        ];
    }

    /** @return array<int, int> */
    private function rodadasProntasIds(): array
    {
        return Pedido::where('pedido_sessao_mesa_id', $this->sessaoId)
            ->where('pedido_status', StatusPedidoEnum::PRONTO->value)
            ->pluck('id')
            ->all();
    }

    private function voltarAoMapa(string $mensagem): void
    {
        Notification::make()->title($mensagem)->warning()->send();
        $this->redirect(MapaMesas::getUrl(), navigate: true);
    }

    private function servico(): AtendimentoMesaService
    {
        return app(AtendimentoMesaService::class);
    }

    private function usuario(): User
    {
        /** @var User */
        return Auth::user();
    }
}
