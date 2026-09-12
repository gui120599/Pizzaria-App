<?php

namespace App\Filament\Resources\SessoesCaixa\Tables;

use App\Enums\StatusSessaoCaixa;
use App\Filament\Resources\SessoesCaixa\Support\FinalizarSessaoCaixaAction;
use App\Filament\Resources\SessoesCaixa\Support\RegistrarSaidaCaixaAction;
use App\Filament\Resources\SessoesCaixa\Support\RegistrarSuprimentoCaixaAction;
use App\Models\SessaoCaixa;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SessoesCaixaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['caixa', 'user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('caixa.caixa_nome')
                    ->label('Caixa')
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Funcionário')
                    ->searchable(),
                TextColumn::make('sessaocaixa_data_hora_abertura')
                    ->label('Abertura')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('sessaocaixa_data_hora_fechamento')
                    ->label('Fechamento')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('sessaocaixa_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusSessaoCaixa::from($state)->getLabel())
                    ->color(fn (string $state): string => StatusSessaoCaixa::from($state)->getColor()),
                TextColumn::make('sessaocaixa_saldo_inicial')
                    ->label('Saldo inicial')
                    ->money('BRL'),
                TextColumn::make('sessaocaixa_saldo_final')
                    ->label('Saldo final')
                    ->money('BRL'),
            ])
            ->filters([
                SelectFilter::make('sessaocaixa_status')
                    ->label('Status')
                    ->options(StatusSessaoCaixa::class),
                SelectFilter::make('sessaocaixa_caixa_id')
                    ->label('Caixa')
                    ->relationship('caixa', 'caixa_nome'),
            ])
            ->recordActions([
                RegistrarSaidaCaixaAction::make(),
                RegistrarSuprimentoCaixaAction::make(),
                FinalizarSessaoCaixaAction::make(),
                Action::make('imprimir')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->url(fn (SessaoCaixa $record): string => route('sessaoCaixa.imprimir', $record))
                    ->openUrlInNewTab(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
