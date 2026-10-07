<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Filament\Concerns\AutorizaComPinDeGerente;
use App\Filament\Garcom\Concerns\CarrinhoLateral;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use RuntimeException;

/**
 * Atendimento de uma conta de mesa/comanda: lançar a rodada, acompanhar as
 * rodadas enviadas e fechar a conta (pré-conta, taxa, Stone).
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

        if (SessaoMesa::whereKey($this->sessaoId)->value('sessao_mesa_status') !== 'ABERTA') {
            $this->voltarAoMapa('A conta desta mesa foi fechada.');
        }
    }

    // ── Rodada ──────────────────────────────────────────────────────────────

    /** Disparado pelo botão do PedidoProdutoSelector (requestSubmit de #pedido-form). */
    public function enviarRodada(): void
    {
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
