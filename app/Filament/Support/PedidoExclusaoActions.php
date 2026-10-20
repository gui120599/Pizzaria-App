<?php

namespace App\Filament\Support;

use App\Enums\MotivoExclusaoPedidoEnum;
use App\Exceptions\PedidoNaoPodeSerExcluidoException;
use App\Exceptions\TransicaoPedidoInvalidaException;
use App\Models\Pedido;
use App\Models\User;
use App\Services\PedidoExclusaoService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Exclusão (soft delete) e restauração de pedido para a lista e a página de
 * visualização. Não existe ForceDelete em lugar nenhum: o Admin passa em
 * qualquer Gate, então a proteção é não oferecer a ação.
 *
 * Toda a regra mora em PedidoExclusaoService; aqui só há formulário e
 * notificação.
 */
class PedidoExclusaoActions
{
    /**
     * Visibilidade barata (sem consulta) usada nas linhas da tabela: status
     * excluível e sem venda. O service confere o resto ao executar.
     */
    public static function podeOferecerExclusao(Pedido $pedido): bool
    {
        return ! $pedido->trashed()
            && (Auth::user()?->can('delete', $pedido) ?? false)
            && in_array($pedido->pedido_status, ['CANCELADO', 'INICIADO', 'ABERTO'], true)
            && $pedido->pedido_venda_id === null;
    }

    public static function excluir(bool $verificacaoCompleta = false): Action
    {
        return Action::make('excluir')
            ->label('Excluir')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Pedido $record): bool => self::podeOferecerExclusao($record)
                && (! $verificacaoCompleta || app(PedidoExclusaoService::class)->podeExcluir($record)))
            ->modalHeading(fn (Pedido $record): string => 'Excluir pedido #'.$record->id)
            ->modalDescription('O pedido vai para a lixeira e pode ser restaurado. Pedido aberto ou iniciado é cancelado antes, com estorno de promoções.')
            ->modalSubmitActionLabel('Excluir pedido')
            ->schema(self::camposExclusao())
            ->action(function (Pedido $record, array $data, Action $action): void {
                try {
                    app(PedidoExclusaoService::class)->excluir(
                        $record,
                        self::ator(),
                        MotivoExclusaoPedidoEnum::from($data['motivo']),
                        $data['observacao'] ?? null,
                    );
                } catch (PedidoNaoPodeSerExcluidoException|TransicaoPedidoInvalidaException $e) {
                    Notification::make()->title('Não foi possível excluir')->body($e->getMessage())->danger()->send();

                    $action->halt();

                    return;
                } catch (AuthorizationException) {
                    Notification::make()->title('Sem permissão para esta ação')->danger()->send();

                    $action->halt();

                    return;
                }

                Notification::make()->title("Pedido #{$record->id} excluído")->success()->send();
            });
    }

    public static function excluirEmLote(): BulkAction
    {
        return BulkAction::make('excluirEmLote')
            ->label('Excluir')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) !== 'lixeira'
                && (Auth::user()?->can('deleteAny', Pedido::class) ?? false))
            ->modalHeading('Excluir pedidos selecionados')
            ->modalDescription('Os pedidos vão para a lixeira e podem ser restaurados. Pedidos que não podem ser excluídos são ignorados.')
            ->schema(self::camposExclusao())
            ->action(function (Collection $records, array $data): void {
                $service = app(PedidoExclusaoService::class);
                $excluidos = 0;
                $ignorados = [];

                foreach ($records as $pedido) {
                    try {
                        $service->excluir(
                            $pedido,
                            self::ator(),
                            MotivoExclusaoPedidoEnum::from($data['motivo']),
                            $data['observacao'] ?? null,
                        );
                        $excluidos++;
                    } catch (PedidoNaoPodeSerExcluidoException|TransicaoPedidoInvalidaException|AuthorizationException $e) {
                        $ignorados[] = "#{$pedido->id}: ".($e instanceof AuthorizationException ? 'sem permissão' : $e->getMessage());
                    }
                }

                $notificacao = Notification::make()
                    ->title("{$excluidos} excluído(s), ".count($ignorados).' ignorado(s)')
                    ->color($ignorados === [] ? 'success' : 'warning');

                if ($ignorados !== []) {
                    $notificacao->body(implode("\n", array_slice($ignorados, 0, 5)));
                }

                $notificacao->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Restauração individual (só na lixeira); o pedido volta CANCELADO. */
    public static function restaurar(): RestoreAction
    {
        return RestoreAction::make()
            ->label('Restaurar')
            ->modalDescription('O pedido volta como cancelado, nunca como ativo.')
            ->visible(fn (Pedido $record, $livewire = null): bool => $record->trashed()
                && (Auth::user()?->can('restore', $record) ?? false))
            ->using(function (Pedido $record): bool {
                try {
                    app(PedidoExclusaoService::class)->restaurar($record, self::ator());
                } catch (AuthorizationException) {
                    return false;
                }

                return true;
            });
    }

    public static function restaurarEmLote(): RestoreBulkAction
    {
        return RestoreBulkAction::make()
            ->label('Restaurar')
            ->visible(fn ($livewire): bool => ($livewire->activeTab ?? null) === 'lixeira'
                && (Auth::user()?->can('restoreAny', Pedido::class) ?? false))
            ->hidden(false)
            ->using(function (Collection $records, RestoreBulkAction $action): void {
                $service = app(PedidoExclusaoService::class);

                foreach ($records as $pedido) {
                    try {
                        $service->restaurar($pedido, self::ator());
                    } catch (AuthorizationException) {
                        $action->reportBulkProcessingFailure();
                    }
                }
            });
    }

    /** @return array<int, Select|Textarea> */
    private static function camposExclusao(): array
    {
        return [
            Select::make('motivo')
                ->label('Motivo')
                ->options(MotivoExclusaoPedidoEnum::paraSelect())
                ->required()
                ->live()
                ->native(false),
            Textarea::make('observacao')
                ->label('Observação')
                ->rows(2)
                ->maxLength(1000)
                ->required(fn (Get $get): bool => $get('motivo') === MotivoExclusaoPedidoEnum::OUTRO->value)
                ->minLength(fn (Get $get): ?int => $get('motivo') === MotivoExclusaoPedidoEnum::OUTRO->value ? 10 : null)
                ->helperText(fn (Get $get): ?string => $get('motivo') === MotivoExclusaoPedidoEnum::OUTRO->value ? 'Descreva o motivo (mínimo de 10 caracteres).' : null),
        ];
    }

    private static function ator(): User
    {
        return Auth::user();
    }
}
