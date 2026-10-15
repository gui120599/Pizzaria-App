<?php

namespace App\Filament\Resources\Colaboradores\Support;

use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Services\Ponto\CadastroColaboradorService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/** Grava o desligamento e encerra as vigências de remuneração e escala na data. */
class DesligarColaboradorAction
{
    public static function make(string $name = 'desligar'): Action
    {
        return Action::make($name)
            ->label('Desligar')
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->authorize('update')
            ->visible(fn (Colaborador $record): bool => $record->data_desligamento === null && ! $record->trashed())
            ->modalHeading(fn (Colaborador $record): string => "Desligar {$record->nome}")
            ->modalDescription('Depois desta data o colaborador não bate ponto e as vigências ficam encerradas. O histórico continua.')
            ->modalSubmitActionLabel('Desligar')
            ->schema([
                DatePicker::make('data')
                    ->label('Último dia')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->default(today())
                    ->required(),
            ])
            ->action(function (Colaborador $record, array $data, CadastroColaboradorService $cadastro, Action $action): void {
                try {
                    $cadastro->desligar($record, Carbon::parse($data['data']));
                } catch (PontoException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Colaborador desligado')->success()->send();
            });
    }
}
