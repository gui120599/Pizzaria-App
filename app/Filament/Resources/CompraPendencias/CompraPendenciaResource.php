<?php

namespace App\Filament\Resources\CompraPendencias;

use App\Filament\Resources\CompraPendencias\Pages\ListCompraPendencias;
use App\Filament\Resources\CompraPendencias\Tables\CompraPendenciasTable;
use App\Models\CompraPendencia;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Caixa de entrada de compras: o que a automação não decidiu sozinha
 * (produto sem de-para, lançamento ambíguo, caixa fechado...) e as sugestões
 * do agente aguardando aprovação. Só leitura + ações; pendência não se cria
 * nem se edita à mão (ver PendenciaService).
 */
class CompraPendenciaResource extends Resource
{
    protected static ?string $model = CompraPendencia::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Caixa de entrada de compras';

    protected static ?string $modelLabel = 'pendência de compra';

    protected static ?string $pluralModelLabel = 'caixa de entrada de compras';

    protected static ?string $slug = 'caixa-entrada-compras';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('resolver:pendencia_compra') ?? false;
    }

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

    public static function getNavigationBadge(): ?string
    {
        $abertas = CompraPendencia::query()->emAberto()->count();

        return $abertas > 0 ? (string) $abertas : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return CompraPendenciasTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['compra.prestador', 'item', 'resolvidaPor']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompraPendencias::route('/'),
        ];
    }
}
