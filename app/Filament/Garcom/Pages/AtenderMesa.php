<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\Garcom\AutorizacaoGerenteService;
use App\Support\ContaMesa;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;

/**
 * Atendimento de uma conta de mesa/comanda: lançar a rodada, acompanhar as
 * rodadas enviadas e fechar a conta (pré-conta, taxa, Stone).
 */
class AtenderMesa extends Page
{
    protected string $view = 'filament.garcom.pages.atender-mesa';

    protected static ?string $slug = 'mesa/{sessao}';

    protected static bool $shouldRegisterNavigation = false;

    public int $sessaoId = 0;

    public int $rascunhoId = 0;

    #[Url]
    public string $aba = 'pedir';

    /** @var array<int, int> */
    public array $prontasAvisadas = [];

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
        $this->aba = 'rodadas';
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

    // ── Apoio ───────────────────────────────────────────────────────────────

    /**
     * Seletor de gerente + PIN, só para quem não tem a permissão da ação.
     *
     * @return array<int, Component>
     */
    private function camposAutorizacao(AcaoAutorizadaEnum $acao): array
    {
        $autorizacoes = app(AutorizacaoGerenteService::class);

        if ($autorizacoes->dispensaPin($this->usuario(), $acao)) {
            return [];
        }

        return [
            Select::make('autorizador_id')
                ->label('Gerente que autoriza')
                ->options($autorizacoes->autorizadoresDisponiveis($acao))
                ->required(),
            TextInput::make('pin')
                ->label('PIN do gerente')
                ->password()
                ->required()
                ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off']),
        ];
    }

    private function executarAutorizado(callable $operacao, string $sucesso): void
    {
        try {
            $operacao();
        } catch (AutorizacaoNegadaException|RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Não foi possível concluir. Tente de novo.')->danger()->send();

            return;
        }

        Notification::make()->title($sucesso)->success()->send();
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
