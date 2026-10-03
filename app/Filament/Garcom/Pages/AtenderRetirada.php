<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Filament\Garcom\Concerns\AutorizaComPinDeGerente;
use App\Filament\Garcom\Concerns\CarrinhoLateral;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\Garcom\RetiradaService;
use App\Support\TotaisPedido;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use RuntimeException;

/**
 * Pedido "para viagem" atendido pelo garçom fora de mesa — retirada ou
 * entrega. Sem {pedido}: monta (cliente + itens no rascunho; na entrega,
 * endereço, frete e forma combinada) e envia para a cozinha. Com {pedido}:
 * acompanha; na retirada, cobra na Stone e entrega ao cliente (a entrega é
 * cobrada pelo entregador).
 */
class AtenderRetirada extends Page
{
    use AutorizaComPinDeGerente;
    use CarrinhoLateral;

    protected string $view = 'filament.garcom.pages.atender-retirada';

    protected static ?string $slug = 'retirada/{pedido?}';

    protected static bool $shouldRegisterNavigation = false;

    /** Pedido já enviado sendo acompanhado (null = montando uma nova). */
    public ?int $pedidoId = null;

    public int $rascunhoId = 0;

    public string $nome = '';

    public string $celular = '';

    public bool $clienteEncontrado = false;

    public bool $prontoAvisado = false;

    /** 'retirada' | 'entrega' — só no modo de montagem. */
    public string $tipo = 'retirada';

    /** Estado do ClientePicker (entrega), recebido por evento. */
    public array $clienteData = [];

    /** Estado do EntregaPagamentoPicker (entrega), recebido por evento. */
    public array $entregaPagamentoData = ['opcaoEntregaId' => null, 'pagamentos' => []];

    public function mount(int|string|null $pedido = null): void
    {
        if ($pedido === null || $pedido === '') {
            // Itens do carrinho chegam do seletor (layoutDesktop notifica no mount).
            $this->rascunhoId = app(RetiradaService::class)->rascunho($this->usuario())->id;

            return;
        }

        $retirada = Pedido::query()
            ->whereKey($pedido)
            ->whereNull('pedido_sessao_mesa_id')
            ->where('pedido_origem', PedidoOrigemEnum::GARCOM->value)
            ->where('pedido_status', '!=', StatusPedidoEnum::INICIADO->value)
            ->first();

        if (! $retirada) {
            Notification::make()->title('Retirada não encontrada.')->warning()->send();
            $this->redirect(MapaMesas::getUrl(['tipo' => 'RETIRADA']), navigate: true);

            return;
        }

        $this->pedidoId = $retirada->id;
        $this->prontoAvisado = $retirada->pedido_status === StatusPedidoEnum::PRONTO->value;
    }

    public function getTitle(): string|Htmlable
    {
        if (! $this->pedidoId) {
            return $this->tipo === 'entrega' ? 'Nova entrega' : 'Nova retirada';
        }

        return (Pedido::with('opcaoEntrega')->find($this->pedidoId)?->exigeEntrega() ? 'Entrega' : 'Retirada')." #{$this->pedidoId}";
    }

    public function retirada(): ?Pedido
    {
        return $this->pedidoId
            ? Pedido::with([
                'cliente:id,cliente_nome,cliente_celular',
                'garcom:id,name,name_first',
                'opcaoEntrega',
                'pagamentosCombinados',
                'item_pedido_pedido_id' => fn ($q) => $q->where('item_pedido_status', 'INSERIDO'),
                'item_pedido_pedido_id.produto:id,produto_descricao',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional:id,adicional_nome',
            ])->find($this->pedidoId)
            : null;
    }

    // ── Montando a retirada ─────────────────────────────────────────────────

    /** Preenche o nome quando o celular já tem cadastro. */
    public function updatedCelular(): void
    {
        $digitos = preg_replace('/\D/', '', $this->celular);

        if (strlen($digitos) < 10) {
            $this->clienteEncontrado = false;

            return;
        }

        $cliente = Cliente::where('cliente_celular', 'like', "%{$digitos}%")->first();
        $this->clienteEncontrado = $cliente !== null;

        if ($cliente) {
            $this->nome = (string) $cliente->cliente_nome;
        }
    }

    public function usarTipo(string $tipo): void
    {
        $this->tipo = $tipo === 'entrega' ? 'entrega' : 'retirada';
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
    }

    /**
     * Total da entrega com frete (TotaisPedido), para o EntregaPagamentoPicker.
     *
     * @return array{itens: float, desconto: float, frete: float, total: float}
     */
    public function totaisEntrega(): array
    {
        $opcaoId = $this->entregaPagamentoData['opcaoEntregaId'] ?? null;

        return TotaisPedido::paraItens(
            collect($this->itensCarrinho)->map(fn (array $item): ItensPedido => new ItensPedido([
                'item_pedido_valor' => $item['valor'] ?? 0,
                'item_pedido_desconto' => $item['desconto'] ?? 0,
            ])),
            $opcaoId ? OpcoesEntregas::find($opcaoId) : null,
        );
    }

    /** Disparado pelo botão do PedidoProdutoSelector (requestSubmit de #pedido-form). */
    public function enviar(): void
    {
        try {
            $rascunho = Pedido::findOrFail($this->rascunhoId);
            $enviada = $this->tipo === 'entrega'
                ? app(RetiradaService::class)->enviarEntrega($rascunho, $this->clienteData, $this->entregaPagamentoData)
                : app(RetiradaService::class)->enviar($rascunho, $this->nome, $this->celular);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return;
        }

        Notification::make()
            ->title($enviada ? 'Pedido enviado para a cozinha.' : 'Este pedido já tinha sido enviado.')
            ->success()
            ->send();

        $this->redirect(static::getUrl(['pedido' => $this->rascunhoId]), navigate: true);
    }

    // ── Acompanhando ────────────────────────────────────────────────────────

    public function atualizar(): void
    {
        $status = Pedido::whereKey($this->pedidoId)->value('pedido_status');

        if ($status === StatusPedidoEnum::PRONTO->value && ! $this->prontoAvisado) {
            $this->prontoAvisado = true;
            Notification::make()->title('Retirada pronta para entregar!')->success()->send();
            $this->dispatch('garcom-pedido-pronto');
        }
    }

    public function entregarAoCliente(): void
    {
        $pedido = Pedido::with('opcaoEntrega')->findOrFail($this->pedidoId);

        // Entrega é finalizada pelo entregador (EM TRANSPORTE → ENTREGUE).
        if ($pedido->exigeEntrega()) {
            return;
        }

        try {
            app(RetiradaService::class)->marcarEntregue($pedido, $this->usuario());
        } catch (TransicaoPedidoInvalidaException) {
            // Outro aparelho já marcou — o próximo render mostra o status atual.
        }

        Notification::make()->title('Retirada entregue ao cliente.')->success()->send();
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
                    ->where('item_pedido_pedido_id', $this->pedidoId)
                    ->firstOrFail();

                $this->executarAutorizado(fn () => app(AtendimentoMesaService::class)->cancelarItem(
                    $item, $this->usuario(), $data['autorizador_id'] ?? null, $data['pin'] ?? null, $data['motivo'],
                ), 'Item cancelado.');
            });
    }

    private function usuario(): User
    {
        /** @var User */
        return Auth::user();
    }
}
