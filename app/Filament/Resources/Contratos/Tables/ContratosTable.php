<?php

namespace App\Filament\Resources\Contratos\Tables;

use App\Enums\StatusContrato;
use App\Filament\Resources\Contratos\Support\GerarLancamentoAgoraAction;
use App\Models\Contrato;
use App\Models\Prestador;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContratosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['favorecido', 'planoDespesa']))
            ->defaultSort('descricao')
            ->columns([
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('favorecido.nome_exibicao')
                    ->label('Fornecedor')
                    ->placeholder('—'),
                TextColumn::make('planoDespesa.nome')
                    ->label('Conta')
                    ->placeholder('—'),
                TextColumn::make('valor')
                    ->label('Valor mensal')
                    ->money('BRL')
                    ->sortable(),
                TextColumn::make('dia_vencimento')
                    ->label('Dia venc.')
                    ->alignCenter(),
                TextColumn::make('proximoVencimento')
                    ->label('Próximo vencimento')
                    ->date('d/m/Y')
                    ->getStateUsing(fn (Contrato $record) => $record->status === StatusContrato::Ativo
                        ? $record->proximoVencimento
                        : null)
                    ->placeholder('—'),
                TextColumn::make('data_inicio')
                    ->label('Início')
                    ->date('d/m/Y')
                    ->toggleable(),
                TextColumn::make('data_fim')
                    ->label('Fim')
                    ->date('d/m/Y')
                    ->placeholder('Indeterminado')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StatusContrato::class),
                SelectFilter::make('favorecido_id')
                    ->label('Fornecedor')
                    // Mesmo gotcha do form: fornecedor PJ pode ter `nome` nulo, então usa
                    // o accessor nome_exibicao em vez de ->relationship() cru.
                    ->options(fn (): array => Prestador::fornecedores()
                        ->orderBy('nome')
                        ->get()
                        ->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])
                        ->toArray())
                    ->searchable(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                GerarLancamentoAgoraAction::make()
                    ->visible(fn (Contrato $record): bool => $record->status === StatusContrato::Ativo),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }
}
