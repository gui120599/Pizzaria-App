<?php

namespace App\Filament\Resources\PontoPeriodos\Pages;

use App\Enums\Ponto\PeriodicidadeRepasse;
use App\Filament\Resources\PontoPeriodos\PontoPeriodoResource;
use App\Models\PontoPeriodo;
use App\Services\Ponto\PeriodosPonto;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

class ListPontoPeriodos extends ListRecords
{
    protected static string $resource = PontoPeriodoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('abrirPeriodo')
                ->label('Abrir período')
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create', PontoPeriodo::class)
                ->modalDescription('Os limites seguem a periodicidade (semana seg–dom, quinzena 1–15/16–fim, mês). O agendamento já abre o atual e o próximo sozinho.')
                ->schema([
                    Select::make('periodicidade')->label('Periodicidade')->options(PeriodicidadeRepasse::class)->required(),
                    DatePicker::make('dia')->label('Um dia do período')->native(false)->displayFormat('d/m/Y')->default(today())->required(),
                    DatePicker::make('data_pagamento')->label('Data de pagamento')->native(false)->displayFormat('d/m/Y')
                        ->helperText('Vazio: '.config('ponto.dias_para_pagamento').' dias depois do fim.'),
                ])
                ->action(function (array $data, PeriodosPonto $periodos): void {
                    $periodicidade = $data['periodicidade'] instanceof PeriodicidadeRepasse ? $data['periodicidade'] : PeriodicidadeRepasse::from($data['periodicidade']);
                    $periodo = $periodos->abrir($periodicidade, Carbon::parse($data['dia']));

                    if ($periodo === null) {
                        Notification::make()->title('Já existe período nessas datas')->warning()->send();

                        return;
                    }

                    if (filled($data['data_pagamento'] ?? null)) {
                        $periodo->update(['data_pagamento' => $data['data_pagamento']]);
                    }

                    Notification::make()->title("Período {$periodo->rotulo()} aberto")->success()->send();
                }),
        ];
    }
}
