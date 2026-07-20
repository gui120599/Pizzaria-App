<?php

namespace App\Filament\Resources\Produtos\RelationManagers;

use App\Models\EstoqueLote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LotesRelationManager extends RelationManager
{
    protected static string $relationship = 'lotes';

    protected static ?string $title = 'Lotes / Marcas';

    protected static ?string $modelLabel = 'lote';

    protected static ?string $pluralModelLabel = 'lotes';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) $ownerRecord->produto_controla_lote;
    }

    /** Só leitura: lotes nascem de uma compra ou de "Registrar Produção", nunca daqui. */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('lote_codigo')
            ->modifyQueryUsing(fn (Builder $query) => $query->fefo()->with('marca'))
            ->emptyStateHeading('Nenhum lote registrado')
            ->emptyStateDescription('Lotes são criados ao confirmar uma compra ou registrar produção.')
            ->emptyStateIcon('heroicon-o-cube')
            ->columns([
                TextColumn::make('lote_codigo')
                    ->label('Lote')
                    ->placeholder('—')
                    ->searchable(),

                ImageColumn::make('marca.marca_imagem')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(32)
                    ->defaultImageUrl(asset('Sem Imagem.png')),

                TextColumn::make('marca.marca_nome')
                    ->label('Marca')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('lote_validade')
                    ->label('Validade')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->description(function (EstoqueLote $record): ?string {
                        if (! $record->lote_validade) {
                            return null;
                        }

                        $dias = (int) now()->startOfDay()->diffInDays($record->lote_validade->copy()->startOfDay(), false);

                        return match (true) {
                            $dias < 0 => 'Vencido há '.abs($dias).' dia(s)',
                            $dias === 0 => 'Vence hoje',
                            default => 'Vence em '.$dias.' dia(s)',
                        };
                    })
                    ->color(fn (EstoqueLote $record): string => match (true) {
                        ! $record->lote_validade => 'gray',
                        $record->lote_validade->isPast() => 'danger',
                        $record->lote_validade->isBefore(now()->addDays(7)) => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('lote_qtd_inicial')
                    ->label('Qtd. inicial')
                    ->numeric(decimalPlaces: 3)
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('lote_qtd_atual')
                    ->label('Saldo atual')
                    ->numeric(decimalPlaces: 3)
                    ->suffix(fn (): string => ' '.($this->getOwnerRecord()->produto_unidade_estoque ?? ''))
                    ->alignEnd()
                    ->weight('bold'),

                TextColumn::make('lote_custo_unitario')
                    ->label('Custo unit.')
                    ->money('BRL')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('lote_status')
                    ->label('Status')
                    ->badge()
                    // "Vencido" é estado derivado (validade < hoje), não fica gravado em lote_status.
                    ->state(function (EstoqueLote $record): string {
                        if ($record->lote_status === 'esgotado') {
                            return 'esgotado';
                        }

                        if ($record->lote_validade?->isPast()) {
                            return 'vencido';
                        }

                        return $record->lote_status;
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'ativo' => 'Ativo',
                        'esgotado' => 'Esgotado',
                        'vencido' => 'Vencido',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'ativo' => 'success',
                        'esgotado' => 'gray',
                        'vencido' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('lote_data_entrada')
                    ->label('Entrada')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('lote_status')
                    ->label('Status (bruto)')
                    ->options([
                        'ativo' => 'Ativo',
                        'esgotado' => 'Esgotado',
                    ]),

                Filter::make('vencidos')
                    ->label('Somente vencidos')
                    ->query(fn (Builder $query) => $query->whereDate('lote_validade', '<', Carbon::today())),

                Filter::make('com_saldo')
                    ->label('Somente com saldo')
                    ->query(fn (Builder $query) => $query->where('lote_qtd_atual', '>', 0))
                    ->default(),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
