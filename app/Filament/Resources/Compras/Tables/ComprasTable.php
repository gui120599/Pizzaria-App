<?php

namespace App\Filament\Resources\Compras\Tables;

use App\Enums\CompraStatusEnum;
use App\Enums\OperacaoEntradaEnum;
use App\Filament\Resources\Compras\Support\ConfirmarCompraAction;
use App\Filament\Resources\Compras\Support\ConverterEmDevolucaoAction;
use App\Filament\Resources\Compras\Support\CorrigirDataEntradaAction;
use App\Filament\Resources\Compras\Support\ImprimirDanfeAction;
use App\Filament\Resources\Compras\Support\RegistrarDevolucaoAction;
use App\Filament\Resources\Compras\Support\RegistrarDevolucaoNotaAction;
use App\Models\Compra;
use App\Models\PrestadorCredito;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComprasTable
{
    /** @var array<string, string> */
    private const ORIGENS = ['xml' => 'NF-e (XML)', 'manual' => 'Manual', Compra::ORIGEM_AVULSA => 'Avulsa (Claude)'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['prestador', 'user'])->withCount(['itens', 'anexos']))
            ->defaultSort('compra_data_entrada', 'desc')
            ->columns([
                TextColumn::make('compra_numero')
                    ->label('NF')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('prestador.nome_exibicao')
                    ->label('Fornecedor')
                    ->searchable(['nome', 'razao_social'])
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('compra_data_entrada')
                    ->label('Entrada')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),

                TextColumn::make('compra_data_emissao')
                    ->label('Emissão NF')
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->sortable(),

                TextColumn::make('itens_count')
                    ->label('Itens')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('compra_valor_total')
                    ->label('Total')
                    ->money('BRL')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('compra_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CompraStatusEnum $state): string => $state->label())
                    ->color(fn (CompraStatusEnum $state): string => $state->cor()),

                TextColumn::make('compra_operacao')
                    ->label('Operação')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OperacaoEntradaEnum::rotulo($state))
                    ->color(fn (?string $state): string => OperacaoEntradaEnum::corDoValor($state))
                    ->placeholder('—'),

                TextColumn::make('compra_origem')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::ORIGENS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'xml' => 'info',
                        Compra::ORIGEM_AVULSA => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (Compra $record): ?string => $record->anexos_count > 0 ? 'heroicon-m-paper-clip' : null)
                    ->tooltip(fn (Compra $record): ?string => $record->anexos_count > 0 ? 'Com comprovante anexado' : null),

                TextColumn::make('user.name')
                    ->label('Registrado por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('credito_fornecedor')
                    ->label('Crédito c/ fornecedor')
                    ->state(fn (Compra $record): float => $record->compra_prestador_id
                        ? (float) PrestadorCredito::doPrestador($record->compra_prestador_id)->naoAplicados()->sum('valor')
                        : 0.0)
                    ->money('BRL')
                    ->color(fn (float $state): string => $state > 0 ? 'success' : 'gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('compra_origem')
                    ->label('Origem')
                    ->options(self::ORIGENS),
                SelectFilter::make('compra_status')
                    ->label('Status')
                    ->options(
                        collect(CompraStatusEnum::cases())
                            ->mapWithKeys(fn ($s) => [$s->value => $s->label()])
                            ->toArray()
                    ),

                SelectFilter::make('compra_operacao')
                    ->label('Operação')
                    ->options(OperacaoEntradaEnum::opcoes()),

                TrashedFilter::make(),
            ])
            ->persistSortInSession()
            ->persistColumnSearchesInSession()
            ->persistColumnsInSession()
            ->persistFiltersInSession()
            ->recordActions([
                ConfirmarCompraAction::make()->label('Confirmar'),
                RegistrarDevolucaoNotaAction::make(),
                ConverterEmDevolucaoAction::make(),
                RegistrarDevolucaoAction::make(),
                CorrigirDataEntradaAction::make(),
                ImprimirDanfeAction::make(),
                EditAction::make()
                    ->visible(fn (Compra $record): bool => $record->isRascunho()),
                DeleteAction::make()
                    ->visible(fn (Compra $record): bool => $record->isRascunho())
                    ->modalDescription('Os títulos a pagar gerados para esta compra serão cancelados junto.')
                    ->failureNotificationTitle('Esta compra tem título com pagamento registrado. Estorne o pagamento antes de excluir.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
