<?php

namespace App\Filament\Influenciador\Resources\Comissoes;

use App\Filament\Influenciador\Resources\Comissoes\Pages\ListComissoes;
use App\Filament\Influenciador\Resources\Comissoes\Tables\ComissoesTable;
use App\Models\Comissao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ComissaoResource extends Resource
{
    protected static ?string $model = Comissao::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Minhas Comissões';

    protected static ?string $modelLabel = 'Comissão';

    // Mesma pegadinha de pluralização em inglês corrigida nos outros slugs.
    protected static ?string $slug = 'comissoes';

    /**
     * Extrato somente leitura — a regra de negócio (aprovação, pagamento)
     * é toda feita pelo staff no painel /admin.
     */
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
            ->where('comissao_influenciador_id', Auth::guard('influenciador')->id());
    }

    public static function table(Table $table): Table
    {
        return ComissoesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComissoes::route('/'),
        ];
    }
}
