<?php

namespace App\Filament\Resources\AgenteExecucoes\Pages;

use App\Filament\Resources\AgenteExecucoes\AgenteExecucaoResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAgenteExecucoes extends ListRecords
{
    protected static string $resource = AgenteExecucaoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revogarAcesso')
                ->label('Revogar acesso do agente')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Revoga todos os tokens do usuário com papel Agente: o conector do Claude para de funcionar até ser autorizado de novo.')
                ->visible(fn (): bool => auth()->user()?->can('configurar:automacao_compra') ?? false)
                ->action(function (): void {
                    $revogados = 0;
                    User::role('Agente')->get()->each(function (User $agente) use (&$revogados): void {
                        $revogados += $agente->tokens()->where('revoked', false)->update(['revoked' => true]);
                    });

                    Notification::make()->title("{$revogados} token(s) revogado(s)")->success()->send();
                }),
        ];
    }
}
