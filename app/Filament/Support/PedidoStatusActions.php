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
use Illuminate\Support\Js;

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

    /**
     * ABERTO → PREPARANDO. Separada de avancar() porque a cozinha lê "Aceitar".
     *
     * $aposAceitar recebe o pedido só quando a transição deu certo — o Painel
     * de Pedidos usa para mandar a comanda para a impressora.
     *
     * @param  (callable(Pedido): void)|null  $aposAceitar
     */
    public static function aceitar(?callable $aposAceitar = null): Action
    {
        return Action::make('aceitar')
            ->label('Aceitar')
            ->icon(Heroicon::OutlinedFire)
            ->color('warning')
            ->action(function (array $arguments) use ($aposAceitar): void {
                $pedido = self::executar(
                    $arguments,
                    fn (PedidoStatusService $s, Pedido $p, ?User $ator) => $s->aceitar($p, $ator),
                );

                if ($pedido && $aposAceitar) {
                    $aposAceitar($pedido);
                }
            });
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

    /**
     * Cancelamento para a tabela de pedidos, que não sabe em que coluna o
     * pedido está: antes da produção (INICIADO/ABERTO) é rejeição, depois é
     * cancelamento com os guards — a mesma escolha do card do painel
     * (painel-pedidos/coluna.blade.php).
     */
    public static function cancelarDaTabela(): Action
    {
        return Action::make('cancelarPedido')
            ->label('Cancelar pedido')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Pedido $record): bool => self::podeCancelar($record))
            ->requiresConfirmation()
            ->modalHeading(fn (Pedido $record): string => 'Cancelar pedido #'.$record->id)
            ->modalDescription('O estoque consumido é estornado e as promoções voltam ao saldo.')
            ->modalSubmitActionLabel('Cancelar pedido')
            ->schema(self::camposMotivo())
            ->action(fn (Pedido $record, array $data) => self::executar(
                ['pedido' => $record->id],
                fn (PedidoStatusService $s, Pedido $p, ?User $ator) => in_array($p->status(), [StatusPedidoEnum::INICIADO, StatusPedidoEnum::ABERTO], true)
                    ? $s->rejeitar($p, $ator, MotivoCancelamentoEnum::from($data['motivo']))
                    : $s->cancelar($p, $ator, MotivoCancelamentoEnum::from($data['motivo'])),
            ));
    }

    /**
     * Copia o link público de acompanhamento (o mesmo que o cardápio manda ao
     * cliente) para o atendente colar no WhatsApp.
     */
    public static function copiarLinkAcompanhamento(): Action
    {
        return Action::make('copiarLinkAcompanhamento')
            ->label('Copiar link de acompanhamento')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->alpineClickHandler(fn (?Pedido $record): string => self::jsCopiarLink(route('pedido.acompanhar', $record?->id)));
    }

    /**
     * JS de "copiar link" compartilhado pela action e pelo card do painel. O
     * Clipboard API só existe em contexto seguro (HTTPS/localhost): fora dele,
     * cai num prompt com o link selecionado para Ctrl+C.
     */
    public static function jsCopiarLink(string $url): string
    {
        $urlJs = Js::from($url);

        return <<<JS
            (window.navigator.clipboard?.writeText({$urlJs}) ?? Promise.reject())
                .then(() => new FilamentNotification().title('Link de acompanhamento copiado').success().send())
                .catch(() => window.prompt('Copie o link de acompanhamento:', {$urlJs}))
            JS;
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

    /**
     * Modal de detalhes completos do pedido: cliente, entrega, itens, valores,
     * pagamento e a linha do tempo de transições — reusado como "Ver detalhes"
     * do menu ⋮ e ao clicar no card.
     */
    public static function verDetalhes(): Action
    {
        return Action::make('verDetalhes')
            ->label('Ver detalhes')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading(fn (array $arguments): string => 'Pedido #'.($arguments['pedido'] ?? ''))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalWidth('3xl')
            ->modalContent(function (array $arguments) {
                $pedido = Pedido::query()
                    ->with([
                        'cliente',
                        'opcaoEntrega',
                        'sessaoMesa.mesa',
                        'entregador:id,name_first',
                        'usuarioCancelou:id,name_first',
                        'pagamentosCombinados',
                        'historicoStatus.usuario:id,name_first',
                        'item_pedido_pedido_id' => fn ($q) => $q
                            ->where('item_pedido_status', 'INSERIDO')
                            ->with(['produto.categoria', 'adicionaisItemPedido.adicional']),
                    ])
                    ->findOrFail($arguments['pedido']);

                return view('filament.pages.painel-pedidos.detalhes-modal', ['pedido' => $pedido]);
            });
    }

    /**
     * QR code + link assinado pro entregador confirmar a entrega pelo celular
     * (Pedido::linkScanEntrega(), já usado no ticket impresso) — poupa o
     * entregador de abrir o painel completo só pra dar baixa numa entrega.
     */
    public static function linkEntrega(): Action
    {
        return Action::make('linkEntrega')
            ->label('Link de entrega')
            ->icon(Heroicon::OutlinedQrCode)
            ->color('gray')
            ->modalHeading('Link de entrega')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (array $arguments) {
                $pedido = self::resolveOrFail($arguments);
                $url = $pedido->linkScanEntrega();

                return view('filament.pages.painel-pedidos.link-entrega-modal', [
                    'pedido' => $pedido,
                    'url' => $url,
                    'whatsappUrl' => 'https://wa.me/?text='.rawurlencode("Link de entrega do pedido #{$pedido->id}: {$url}"),
                ]);
            });
    }

    /**
     * Troca o entregador SEM mexer no status — pro caso do entregador ficar
     * indisponível no meio da rota. Ver PedidoStatusService::reatribuirEntregador().
     */
    public static function trocarEntregador(): Action
    {
        return Action::make('trocarEntregador')
            ->label('Trocar entregador')
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('gray')
            ->schema([
                Select::make('entregador')
                    ->label('Entregador')
                    ->options(fn () => User::role('Entregador')->pluck('name_first', 'id'))
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $pedido = self::resolveOrFail($arguments);
                $entregador = User::find($data['entregador']);

                if (! $entregador) {
                    Notification::make()->title('Entregador não encontrado')->danger()->send();

                    return;
                }

                try {
                    app(PedidoStatusService::class)->reatribuirEntregador($pedido, $entregador, Auth::user());
                } catch (AuthorizationException) {
                    Notification::make()->title('Sem permissão para esta ação')->danger()->send();

                    return;
                }

                Notification::make()->title('Entregador atualizado')->success()->send();
            });
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

    /** Só faz sentido quando a operação passa por "Em transporte" de verdade. */
    public static function podeLinkEntrega(Pedido $pedido): bool
    {
        return $pedido->usaEstagioTransporte()
            && in_array($pedido->status(), [StatusPedidoEnum::PRONTO, StatusPedidoEnum::EM_TRANSPORTE], true);
    }

    /**
     * Visível quando já há um entregador atribuído (pra trocar) ou o pedido
     * está numa etapa em que atribuir um faz sentido.
     */
    public static function podeTrocarEntregador(Pedido $pedido): bool
    {
        if (! self::atribuiEntregador()) {
            return false;
        }

        return $pedido->pedido_usuario_entrega_id !== null
            || in_array($pedido->status(), [StatusPedidoEnum::PRONTO, StatusPedidoEnum::EM_TRANSPORTE], true);
    }

    /** @return array<int, Select> */
    public static function camposMotivo(): array
    {
        return [
            Select::make('motivo')
                ->label('Motivo')
                ->options(MotivoCancelamentoEnum::paraSelect())
                ->required()
                ->native(false),
        ];
    }

    /** Resolve o pedido dos argumentos ou lança 404 — usado nas ações que só leem. */
    private static function resolveOrFail(array $arguments): Pedido
    {
        return Pedido::findOrFail($arguments['pedido']);
    }

    /**
     * Resolve o pedido dos argumentos, roda a transição e traduz o resultado em
     * notificação. Centralizado para que toda ação trate os erros igual.
     *
     * @param  array<string, mixed>  $arguments
     * @param  callable(PedidoStatusService, Pedido, ?User): Pedido  $transicao
     * @return ?Pedido o pedido atualizado, ou null quando a transição não ocorreu
     */
    private static function executar(array $arguments, callable $transicao): ?Pedido
    {
        $pedido = Pedido::find($arguments['pedido'] ?? null);

        if (! $pedido) {
            Notification::make()
                ->title('Pedido não encontrado')
                ->danger()
                ->send();

            return null;
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

            return null;
        } catch (AuthorizationException) {
            Notification::make()
                ->title('Sem permissão para esta ação')
                ->danger()
                ->send();

            return null;
        }

        Notification::make()
            ->title("Pedido #{$atualizado->id} → ".($atualizado->status()?->label() ?? $atualizado->pedido_status))
            ->success()
            ->send();

        return $atualizado;
    }

    private static function atribuiEntregador(): bool
    {
        return (bool) config('pizzaria.pedidos.atribui_entregador', true);
    }
}
