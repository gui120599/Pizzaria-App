<?php

namespace App\Filament\Resources\Clientes\Tables\Actions;

use App\Models\Cliente;
use App\Services\ClienteMergeService;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Consolida clientes duplicados/triplicados: reatribui pedidos, vendas,
 * sessões de mesa, lançamentos e prestadores do(s) cadastro(s) escolhido(s)
 * como "perdedor" para o cadastro "principal", e move os perdedores para a
 * lixeira. Regra de negócio vive em ClienteMergeService — esta classe só
 * monta a UI (modal de escolha do principal) e delega.
 */
class UnificarClientesBulkAction
{
    public static function make(): BulkAction
    {
        return BulkAction::make('unificarClientes')
            ->label('Unificar clientes')
            ->icon('heroicon-o-arrows-pointing-in')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->hasRole('Admin') ?? false)
            ->modalHeading('Unificar clientes duplicados')
            ->modalDescription(
                'Escolha qual cadastro deve permanecer. Os demais terão pedidos, '.
                'vendas, sessões de mesa, lançamentos e prestadores reatribuídos '.
                'para o cadastro escolhido e serão movidos para a lixeira.'
            )
            ->modalSubmitActionLabel('Unificar')
            ->form(fn (Collection $records): array => [
                Radio::make('cliente_principal_id')
                    ->label('Cadastro que permanece')
                    ->options(
                        $records->mapWithKeys(fn (Cliente $cliente): array => [
                            $cliente->id => self::descricaoOpcao($cliente),
                        ])
                    )
                    ->default(
                        $records->sortByDesc(fn (Cliente $c) => $c->pedidos_count ?? 0)->first()?->id
                    )
                    ->required(),
            ])
            ->action(function (Collection $records, array $data, ClienteMergeService $service): void {
                if ($records->count() < 2) {
                    Notification::make()
                        ->title('Selecione ao menos 2 clientes para unificar')
                        ->warning()
                        ->send();

                    return;
                }

                $principal = $records->firstWhere('id', (int) $data['cliente_principal_id']);

                if (! $principal) {
                    Notification::make()->title('Cadastro principal inválido')->danger()->send();

                    return;
                }

                $perdedores = $records->reject(fn (Cliente $c): bool => $c->id === $principal->id);

                try {
                    $service->unificar($principal, $perdedores);
                } catch (\Throwable $e) {
                    report($e);
                    Notification::make()
                        ->title('Falha ao unificar clientes')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Clientes unificados com sucesso')
                    ->body("Cadastro #{$principal->id} passa a concentrar os dados dos ".$perdedores->count().' cadastro(s) unificado(s).')
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function descricaoOpcao(Cliente $cliente): string
    {
        $documento = $cliente->cliente_cpf ?: ($cliente->cliente_cnpj ?: 'sem documento');
        $celular = $cliente->cliente_celular ?: 'sem celular';
        $pedidos = $cliente->pedidos_count ?? 0;

        return "#{$cliente->id} — {$cliente->cliente_nome} | doc: {$documento} | cel: {$celular} | {$pedidos} pedido(s)";
    }
}
