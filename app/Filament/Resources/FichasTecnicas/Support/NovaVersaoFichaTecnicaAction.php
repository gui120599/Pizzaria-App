<?php

namespace App\Filament\Resources\FichasTecnicas\Support;

use App\Models\FichaTecnica;
use App\Services\Cozinha\FichaTecnicaService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Marca uma revisão da receita. A versão já sobe sozinha quando uma ficha
 * usada na produção muda; esta ação serve para registrar uma revisão sem
 * mudar campo (ex.: treinamento novo).
 */
class NovaVersaoFichaTecnicaAction
{
    public static function make(): Action
    {
        return Action::make('novaVersao')
            ->label('Nova versão')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('update')
            ->requiresConfirmation()
            ->modalDescription(fn (FichaTecnica $record): string => "A ficha passa da versão {$record->versao} para a ".($record->versao + 1).'. Os itens já produzidos continuam apontando para a versão que usaram.')
            ->action(function (FichaTecnica $record): void {
                app(FichaTecnicaService::class)->novaVersao($record);

                Notification::make()->success()->title("Ficha agora na versão {$record->versao}")->send();
            });
    }
}
