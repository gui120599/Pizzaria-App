<?php

namespace App\Filament\Resources\PagamentosComissao;

use App\Filament\Resources\PagamentosComissao\Pages\ManagePagamentosComissao;
use App\Filament\Resources\PagamentosComissao\Tables\PagamentosComissaoTable;
use App\Models\PagamentoComissao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PagamentoComissaoResource extends Resource
{
    protected static ?string $model = PagamentoComissao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Pagamentos de Comissão';

    protected static ?string $slug = 'pagamentos-comissao';

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?int $navigationSort = 27;

    /** Gerado só pela bulk action "Gerar pagamento" em ComissaoResource. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PagamentosComissaoTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePagamentosComissao::route('/'),
        ];
    }
}
