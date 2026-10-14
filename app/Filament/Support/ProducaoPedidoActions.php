<?php

namespace App\Filament\Support;

use App\Enums\Cozinha\PrioridadeProducao;
use App\Models\Pedido;
use App\Models\ProducaoItem;
use App\Services\Cozinha\ProducaoService;
use Filament\Actions\Action;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * Ações de produção no Painel de Pedidos (spec §7.1), no mesmo formato de
 * PedidoStatusActions: montadas uma vez na página e disparadas por card com
 * `mountAction('verProducao', { pedido: 123 })`. A regra fica no
 * ProducaoService; aqui só autorização e notificação.
 */
class ProducaoPedidoActions
{
    /** Linha do tempo de cada item de produção, a partir de producao_eventos. */
    public static function verProducao(): Action
    {
        return Action::make('verProducao')
            ->label('Ver produção')
            ->icon(Heroicon::OutlinedFire)
            ->color('gray')
            ->authorize(fn (): bool => Gate::allows('viewAny', ProducaoItem::class))
            ->modalHeading(fn (array $arguments): string => 'Produção do pedido #'.($arguments['pedido'] ?? ''))
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(function (array $arguments) {
                $pedido = Pedido::findOrFail($arguments['pedido'] ?? 0);
                $itens = ProducaoItem::query()
                    ->where('pedido_id', $pedido->id)
                    ->with(['setor:id,nome,cor', 'eventos' => fn ($q) => $q->orderBy('id'), 'eventos.usuario:id,name_first', 'eventos.operador:id,name_first'])
                    ->orderBy('item_pedido_id')
                    ->orderBy('unidade_seq')
                    ->orderBy('etapa')
                    ->orderBy('geracao')
                    ->get();

                return view('filament.cozinha.producao-pedido-modal', ['pedido' => $pedido, 'itens' => $itens]);
            });
    }

    public static function priorizar(): Action
    {
        return Action::make('priorizar')
            ->label('Priorizar')
            ->icon(Heroicon::OutlinedBolt)
            ->color('warning')
            ->authorize(fn (): bool => Gate::allows('priorizar', ProducaoItem::class))
            ->modalHeading(fn (array $arguments): string => 'Prioridade do pedido #'.($arguments['pedido'] ?? ''))
            ->modalDescription('Os itens ainda na cozinha sobem na fila de todos os setores.')
            ->modalWidth(Width::Medium)
            ->fillForm(fn (array $arguments): array => [
                'prioridade' => Pedido::whereKey($arguments['pedido'] ?? 0)->value('pedido_prioridade_producao') ?? PrioridadeProducao::Alta->value,
            ])
            ->schema([
                ToggleButtons::make('prioridade')
                    ->label('Prioridade')
                    ->options(PrioridadeProducao::class)
                    ->inline()
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $pedido = Pedido::findOrFail($arguments['pedido'] ?? 0);
                $prioridade = $data['prioridade'] instanceof PrioridadeProducao ? $data['prioridade'] : PrioridadeProducao::from($data['prioridade']);

                app(ProducaoService::class)->priorizar($pedido, $prioridade);

                Notification::make()->success()->title("Pedido #{$pedido->id}: prioridade {$prioridade->getLabel()}")->send();
            });
    }

    /** Solta na fila os itens segurados pelo disparo por tempo de preparo. */
    public static function liberarAgora(): Action
    {
        return Action::make('liberarProducao')
            ->label('Liberar agora')
            ->icon(Heroicon::OutlinedPlay)
            ->color('info')
            ->authorize(fn (): bool => Gate::allows('priorizar', ProducaoItem::class))
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Liberar a produção do pedido #'.($arguments['pedido'] ?? ''))
            ->modalDescription('Os itens que esperavam a hora de começar (para sair junto com o resto) entram na fila agora.')
            ->modalSubmitActionLabel('Liberar')
            ->action(function (array $arguments): void {
                $pedido = Pedido::findOrFail($arguments['pedido'] ?? 0);
                $liberados = app(ProducaoService::class)->liberarPedido($pedido);

                Notification::make()
                    ->title($liberados > 0 ? "{$liberados} item(ns) liberado(s) para a cozinha" : 'Nenhum item estava segurado')
                    ->status($liberados > 0 ? 'success' : 'info')
                    ->send();
            });
    }
}
