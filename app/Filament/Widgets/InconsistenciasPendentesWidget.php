<?php

namespace App\Filament\Widgets;

use App\Enums\Ponto\SituacaoDia;
use App\Enums\Ponto\StatusPeriodo;
use App\Filament\Pages\EspelhoPonto;
use App\Models\PontoDia;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Dias incompletos ou inconsistentes de períodos ainda abertos (o que bloqueia/atrapalha o fechamento). */
class InconsistenciasPendentesWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('view_espelho:ponto') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Inconsistências pendentes')
            ->query(fn (): Builder => PontoDia::query()
                ->with(['colaborador', 'periodo'])
                ->whereIn('situacao', [SituacaoDia::Incompleto, SituacaoDia::Inconsistente])
                ->whereHas('periodo', fn (Builder $q) => $q->where('status', StatusPeriodo::Aberto)))
            ->defaultSort('data_referencia', 'desc')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Nenhuma inconsistência em período aberto')
            ->recordUrl(fn (PontoDia $record): string => EspelhoPonto::getUrl(['periodo' => $record->ponto_periodo_id]))
            ->columns([
                TextColumn::make('data_referencia')->label('Dia')->date('d/m'),
                TextColumn::make('colaborador.nome')->label('Colaborador'),
                TextColumn::make('situacao')->label('Situação')->badge(),
                TextColumn::make('inconsistencias')->label('O quê')
                    ->state(fn (PontoDia $record): string => implode(', ', $record->rotulosInconsistencias())),
            ]);
    }
}
