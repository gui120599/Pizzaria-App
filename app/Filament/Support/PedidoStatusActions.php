<?php

namespace App\Filament\Support;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Models\Pedido;
use App\Models\User;
use App\Services\PedidoStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Catálogo das ações de mudança de status do pedido.
 *
 * Mora aqui, e não dentro da Page, para que o Painel de Pedidos, a
 * PedidosTable e o AtenderPedido ofereçam a MESMA ação — com o mesmo rótulo, a
 * mesma confirmação e a mesma regra de visibilidade. Cada ação é montada uma
 * vez na página e disparada por card via
 * `wire:click="mountAction('avancar', { pedido: 123 })"`.
 *
 * Erros de domínio viram notificação em vez de tela de exceção: quando dois
 * operadores mexem no mesmo pedido, o segundo precisa entender o que aconteceu,
 * não ver um 500.
 */
class PedidoStatusActions
{
    /**
     * Avança um degrau no fluxo. O rótulo diz o destino, que depende do pedido
     * (de PRONTO, delivery vai para "Em transporte" e balcão para "Entregue").
     */
    public static function avancar(): Action
    {
        return Action::make('avancar')
            ->label('Avançar')
            ->icon(Heroicon::OutlinedArrowRight)
            ->color('primary')
            ->action(fn (array $arguments) => self::executar(
                $arguments,
                fn (PedidoStatusService $s, Pedido $p, ?User $ator) => $s->avancar($p, $ator),
            ));
    }

    /** ABERTO → PREPARANDO. Separada de avancar() porque a cozinha lê "Aceitar". */
    public static function aceitar(): Action
    {
        return Action::make('aceitar')
            ->label('Aceitar')
            ->icon(Heroicon::OutlinedFire)
            ->color('warning')
            ->action(fn (array $arguments) => self::executar(
                $arguments,
                fn (PedidoStatusService $s, Pedido $p, ?User $ator) => $s->aceitar($p, $ator),
            ));
    }

    /** INICIADO → ABERTO, para o pedido que chegou pelo cardápio. */
    public static function confirmar(): Action
    {
        return Action::make('confirmar')
            ->label('Confirmar')
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->action(fn (array $arguments) => self::executar(
                $arguments,
                fn (PedidoStatusService $s, Pedido $p, ?User $ator) => $s->confirmar($p, $ator),
            ));
    }

    /**
     * PRONTO → EM TRANSPORTE escolhendo o entregador. O select só aparece quando
     * a operação atribui entregador (config); sem isso, a ação é um clique só.
     */
    public static function despachar(): Action
    {
        return Action::make('despachar')
            ->label('Despachar')
            ->icon(Heroicon::OutlinedTruck)
            ->color('info')
            ->schema(fn (): array => self::atribuiEntregador() ? [
                Select::make('entregador')
                    ->label('Entregador')
                    ->options(fn () => User::role('Entregador')->pluck('name_first', 'id'))
                    ->searchable()
                    ->required(),
            ] : [])
            ->action(fn (array $arguments, array $data) => self::executar(
                $arguments,
                fn (PedidoStatusService $s, Pedido $p, ?User $ator) => $s->despachar(
                    $p,
                    $ator,
                    isset($data['entregador']) ? User::find($data['entregador']) : null,
                ),
            ));
    }

    /**
     * Rejeita um pedido que ainda não entrou em produção.
     *
     * O motivo é obrigatório — a tela legada chamava o endpoint de rejeição sem
     * perguntar nada e gravava MotivoCancelamentoEnum::OUTRO em tudo, o que
     * inutilizava o relatório de motivos de cancelamento.
     */
    public static function rejeitar(): Action
    {
        return Action::make('rejeitar')
            ->label('Rejeitar')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Rejeitar pedido #'.($arguments['pedido'] ?? ''))
            ->modalDescription('O pedido é cancelado e as promoções usadas voltam ao saldo.')
            ->modalSubmitActionLabel('Rejeitar pedido')
            ->schema(self::camposMotivo())
            ->action(fn (array $arguments, array $data) => self::executar(
                $arguments,
                fn (PedidoStatusService $s, Pedido $p, ?User $ator) => $s->rejeitar(
                    $p,
                    $ator,
                    MotivoCancelamentoEnum::from($data['motivo']),
                ),
            ));
    }

    /** Cancela um pedido já em produção — aplica os guards de mesa e pagamento. */
    public static function cancelar(): Action
    {
        return Action::make('cancelar')
            ->label('Cancelar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Cancelar pedido #'.($arguments['pedido'] ?? ''))
            ->modalDescription('O estoque consumido é estornado e as promoções voltam ao saldo.')
            ->modalSubmitActionLabel('Cancelar pedido')
            ->schema(self::camposMotivo())
            ->action(fn (array $arguments, array $data) => self::executar(
                $arguments,
                fn (PedidoStatusService $s, Pedido $p, ?User $ator) => $s->cancelar(
                    $p,
                    $ator,
                    MotivoCancelamentoEnum::from($data['motivo']),
                ),
            ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Visibilidade — mesma regra para board, tabela e tela de atendimento
    // ─────────────────────────────────────────────────────────────────────────

    public static function podeAvancar(Pedido $pedido): bool
    {
        $status = $pedido->status();

        return $status !== null
            && $status->podeAvancarGenericamente()
            && $status->proximo($pedido->exigeEntrega()) !== null;
    }

    public static function podeDespachar(Pedido $pedido): bool
    {
        return $pedido->status() === StatusPedidoEnum::PRONTO && $pedido->usaEstagioTransporte();
    }

    public static function podeCancelar(Pedido $pedido): bool
    {
        return (bool) $pedido->status()?->podeSerCancelado();
    }

    /** @return array<int, Select> */
    private static function camposMotivo(): array
    {
        return [
            Select::make('motivo')
                ->label('Motivo')
                ->options(MotivoCancelamentoEnum::paraSelect())
                ->required()
                ->native(false),
        ];
    }

    /**
     * Resolve o pedido dos argumentos, roda a transição e traduz o resultado em
     * notificação. Centralizado para que toda ação trate os erros igual.
     *
     * @param  array<string, mixed>  $arguments
     * @param  callable(PedidoStatusService, Pedido, ?User): Pedido  $transicao
     */
    private static function executar(array $arguments, callable $transicao): void
    {
        $pedido = Pedido::find($arguments['pedido'] ?? null);

        if (! $pedido) {
            Notification::make()
                ->title('Pedido não encontrado')
                ->danger()
                ->send();

            return;
        }

        try {
            $atualizado = $transicao(app(PedidoStatusService::class), $pedido, Auth::user());
        } catch (TransicaoPedidoInvalidaException $e) {
            // Caso típico: outro operador mexeu no pedido antes. A tela precisa
            // recarregar para mostrar onde ele está de verdade.
            Notification::make()
                ->title('Pedido já foi atualizado')
                ->body($e->getMessage())
                ->warning()
                ->persistent()
                ->send();

            return;
        } catch (AuthorizationException) {
            Notification::make()
                ->title('Sem permissão para esta ação')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title("Pedido #{$atualizado->id} → ".($atualizado->status()?->label() ?? $atualizado->pedido_status))
            ->success()
            ->send();
    }

    private static function atribuiEntregador(): bool
    {
        return (bool) config('pizzaria.pedidos.atribui_entregador', true);
    }
}
