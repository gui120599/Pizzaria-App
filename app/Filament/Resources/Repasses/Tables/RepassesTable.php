<?php

namespace App\Filament\Resources\Repasses\Tables;

use App\Enums\Ponto\StatusRepasse;
use App\Enums\Ponto\VinculoColaborador;
use App\Filament\Resources\Repasses\RepasseResource;
use App\Filament\Resources\Repasses\Support\RepasseActions;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RepassesTable
{
    /** Também usada pela aba de repasses do período (sem a coluna/filtro de período). */
    public static function configure(Table $table, bool $doPeriodo = false): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['colaborador', 'periodo', 'lancamento']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('periodo.data_inicio')->label('Período')
                    ->state(fn (Repasse $record): string => $record->periodo->rotulo())
                    ->hidden($doPeriodo),
                TextColumn::make('colaborador.nome')->label('Colaborador')->searchable(),
                TextColumn::make('colaborador.vinculo')->label('Vínculo')->badge()->toggleable(),
                TextColumn::make('total_proventos')->label('Proventos')->money('BRL')->alignEnd()->toggleable(),
                TextColumn::make('total_descontos')->label('Descontos')->money('BRL')->alignEnd()->toggleable(),
                TextColumn::make('valor_liquido')->label('Líquido')->money('BRL')->alignEnd()->weight('bold')
                    ->color(fn (Repasse $record): ?string => (float) $record->valor_liquido < 0 ? 'danger' : null)
                    ->summarize(Sum::make()->label('Total')->money('BRL')),
                TextColumn::make('status')->label('Situação')->badge(),
                IconColumn::make('conferido_holerite')->label('Holerite')
                    ->state(fn (Repasse $record): ?bool => $record->colaborador->vinculo === VinculoColaborador::Clt ? $record->conferido_holerite : null)
                    ->boolean()->alignCenter()->toggleable(),
                TextColumn::make('lancamento.status')->label('Financeiro')->badge()->placeholder('—'),
                TextColumn::make('ciente_em')->label('Ciência')->dateTime('d/m H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(StatusRepasse::class),
                SelectFilter::make('ponto_periodo_id')->label('Período')
                    ->options(fn (): array => PontoPeriodo::query()->orderByDesc('data_inicio')->limit(60)->get()
                        ->mapWithKeys(fn (PontoPeriodo $periodo): array => [$periodo->id => $periodo->periodicidade->getLabel().' '.$periodo->rotulo()])
                        ->all())
                    ->hidden($doPeriodo),
            ])
            ->recordActions([
                ViewAction::make()->url(fn (Repasse $record): string => RepasseResource::getUrl('view', ['record' => $record])),
                RepasseActions::aprovar(),
                ActionGroup::make([
                    RepasseActions::recalcular(),
                    RepasseActions::recibo(),
                    RepasseActions::cancelarAprovacao(),
                ]),
            ]);
    }
}
