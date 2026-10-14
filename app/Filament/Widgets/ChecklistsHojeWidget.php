<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ChecklistExecucoes\ChecklistExecucaoResource;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Cada modelo esperado no dia operacional × status da execução. */
class ChecklistsHojeWidget extends TableWidget
{
    use HasWidgetShield;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $dia = ChecklistExecucao::dataOperacional();

        return $table
            ->heading('Checklists de hoje ('.$dia->format('d/m').')')
            ->query(fn (): Builder => ChecklistModelo::query()
                ->ativos()
                ->where(fn (Builder $query): Builder => $query
                    ->agendadosPara($dia)
                    ->orWhereHas('execucoes', fn (Builder $execucoes): Builder => $execucoes->doDia($dia)))
                ->with(['execucoes' => fn ($execucoes) => $execucoes->doDia($dia)->with('iniciadoPor')->latest('id')]))
            ->defaultSort('janela_inicio')
            ->paginated(false)
            ->emptyStateHeading('Nenhum checklist previsto para hoje')
            ->recordUrl(fn (ChecklistModelo $record): ?string => ($execucao = $record->execucoes->first())
                ? ChecklistExecucaoResource::getUrl('view', ['record' => $execucao])
                : null)
            ->columns([
                TextColumn::make('nome')
                    ->label('Checklist'),
                TextColumn::make('setor')
                    ->label('Setor')
                    ->badge(),
                TextColumn::make('janela_inicio')
                    ->label('Janela')
                    ->state(fn (ChecklistModelo $record): ?string => $record->janelaDescricao())
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ChecklistModelo $record): string => $record->execucoes->first()?->status->getLabel() ?? 'Não gerado')
                    ->color(fn (ChecklistModelo $record): string => $record->execucoes->first()?->status->getColor() ?? 'gray'),
                TextColumn::make('progresso')
                    ->label('Progresso')
                    ->state(fn (ChecklistModelo $record): ?string => ($execucao = $record->execucoes->first())
                        ? "{$execucao->itens_respondidos}/{$execucao->total_itens}"
                        : null)
                    ->placeholder('—'),
                TextColumn::make('responsavel')
                    ->label('Iniciado por')
                    ->state(fn (ChecklistModelo $record): ?string => $record->execucoes->first()?->iniciadoPor?->name)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('painel')
                    ->label('Painel')
                    ->options(ChecklistModelo::opcoesDePainel())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'])
                        ? $query->paraPainel($data['value'])
                        : $query),
            ]);
    }
}
