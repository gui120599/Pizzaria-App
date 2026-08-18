<?php

namespace App\Filament\Resources\SessoesMesa\Support;

use App\Models\Mesa;
use App\Models\SessaoMesa;
use App\Services\SessaoMesaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/** Mesma regra de SessaoMesaController::updateAlterarMesaSessaMesa (via SessaoMesaService). */
class TrocarMesaAction
{
    public static function make(string $name = 'trocarMesa'): Action
    {
        return Action::make($name)
            ->label('Trocar de mesa')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (SessaoMesa $record): bool => in_array($record->sessao_mesa_status, ['ABERTA', 'FECHADA'], true)
                && auth()->user()?->can('update', $record))
            ->schema([
                Select::make('mesa_id_nova')
                    ->label('Nova mesa')
                    ->options(fn (SessaoMesa $record): array => Mesa::query()
                        ->where('mesa_status', 'LIBERADA')
                        ->where('id', '!=', $record->sessao_mesa_mesa_id)
                        ->orderBy('mesa_nome')
                        ->pluck('mesa_nome', 'id')
                        ->toArray())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (array $data, SessaoMesa $record, SessaoMesaService $service): void {
                $service->trocarMesa($record, (int) $data['mesa_id_nova']);

                Notification::make()
                    ->title('Mesa alterada com sucesso!')
                    ->success()
                    ->send();
            });
    }
}
