<?php

namespace App\Filament\Resources\ResgatesCarteira;

use App\Filament\Resources\ResgatesCarteira\Pages\ListResgatesCarteira;
use App\Models\ResgateCarteira;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Resgates em Pix da carteira dos influenciadores: só leitura. O resgate nasce
 * da ação "Pagar saldo em Pix" no cadastro do influenciador (ResgatePixService).
 */
class ResgateCarteiraResource extends Resource
{
    protected static ?string $model = ResgateCarteira::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Resgates de Influenciadores';

    protected static ?string $modelLabel = 'Resgate';

    protected static ?string $slug = 'resgates-carteira';

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?int $navigationSort = 27;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['influenciador', 'usuario']))
            ->defaultSort('rc_pago_em', 'desc')
            ->emptyStateHeading('Nenhum resgate realizado')
            ->columns([
                TextColumn::make('rc_pago_em')->label('Pago em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('influenciador.influenciador_nome')->label('Influenciador')->searchable(),
                TextColumn::make('rc_valor')->label('Valor')->money('BRL')->weight('bold'),
                TextColumn::make('usuario.name')->label('Pago por')->placeholder('—'),
                TextColumn::make('rc_lancamento_id')->label('Título')->prefix('#')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('rc_influenciador_id')
                    ->label('Influenciador')
                    ->relationship('influenciador', 'influenciador_nome')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('recibo')
                    ->label('Recibo')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (ResgateCarteira $record): string => route('resgates-carteira.recibo', $record))
                    ->openUrlInNewTab(),
                Action::make('comprovante')
                    ->label('Comprovante')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn (ResgateCarteira $record): bool => filled($record->rc_comprovante_path))
                    ->url(fn (ResgateCarteira $record): string => route('resgates-carteira.comprovante', $record))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResgatesCarteira::route('/'),
        ];
    }
}
