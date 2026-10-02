<?php

namespace App\Filament\Garcom\Pages;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Filament\Garcom\Concerns\AutorizaComPinDeGerente;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\Garcom\RetiradaService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Retirada atendida pelo garçom fora de mesa. Sem {pedido}: monta a retirada
 * (cliente + itens no rascunho) e envia para a cozinha. Com {pedido}:
 * acompanha, cobra na Stone e entrega ao cliente.
 */
class AtenderRetirada extends Page
{
    use AutorizaComPinDeGerente;

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

    public function mount(int|string|null $pedido = null): void
    {
        if ($pedido === null || $pedido === '') {
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
        return $this->pedidoId ? "Retirada #{$this->pedidoId}" : 'Nova retirada';
    }

    public function retirada(): ?Pedido
    {
        return $this->pedidoId
            ? Pedido::with([
                'cliente:id,cliente_nome,cliente_celular',
                'garcom:id,name,name_first',
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

    /** Disparado pelo botão do PedidoProdutoSelector (requestSubmit de #pedido-form). */
    public function enviar(): void
    {
        try {
            $enviada = app(RetiradaService::class)->enviar(Pedido::findOrFail($this->rascunhoId), $this->nome, $this->celular);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();

            return;
        }

        Notification::make()
            ->title($enviada ? 'Retirada enviada para a cozinha.' : 'Esta retirada já tinha sido enviada.')
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
        $pedido = Pedido::findOrFail($this->pedidoId);

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
