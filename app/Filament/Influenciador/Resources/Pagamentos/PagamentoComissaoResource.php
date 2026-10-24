<?php

namespace App\Filament\Influenciador\Resources\Pagamentos;

use App\Filament\Influenciador\Resources\Pagamentos\Pages\ListPagamentos;
use App\Filament\Influenciador\Resources\Pagamentos\Tables\PagamentosTable;
use App\Models\PagamentoComissao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PagamentoComissaoResource extends Resource
{
    protected static ?string $model = PagamentoComissao::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Pagamentos Recebidos';

    protected static ?string $modelLabel = 'Pagamento';

    protected static ?string $slug = 'pagamentos-comissao';

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('pgcom_influenciador_id', Auth::guard('influenciador')->id());
    }

    public static function table(Table $table): Table
    {
        return PagamentosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPagamentos::route('/'),
        ];
    }
}
