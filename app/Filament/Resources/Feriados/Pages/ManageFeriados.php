<?php

namespace App\Filament\Resources\Feriados\Pages;

use App\Filament\Resources\Feriados\FeriadoResource;
use App\Models\Feriado;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageFeriados extends ManageRecords
{
    protected static string $resource = FeriadoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importarNacionais')
                ->label('Importar feriados nacionais')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize('create', Feriado::class)
                ->modalDescription('Carnaval e Corpus Christi entram como ponto facultativo. Feriados já cadastrados não se repetem.')
                ->schema([
                    TextInput::make('ano')->label('Ano')->numeric()->integer()->minValue(2000)->maxValue(2100)->default(now()->year)->required(),
                ])
                ->action(function (array $data): void {
                    $criados = Feriado::importarNacionais((int) $data['ano']);

                    Notification::make()
                        ->title($criados > 0 ? "{$criados} feriado(s) importado(s)" : 'Nada novo para importar')
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
