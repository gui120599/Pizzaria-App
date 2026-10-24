<?php

namespace App\Filament\Resources\Comissoes;

use App\Filament\Resources\Comissoes\Pages\ManageComissoes;
use App\Filament\Resources\Comissoes\Tables\ComissoesTable;
use App\Models\Comissao;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ComissaoResource extends Resource
{
    protected static ?string $model = Comissao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Comissões';

    // Sem isso, o Filament pluralizaria "comissao" pelas regras do inglês
    // ("comissaos") — mesma pegadinha corrigida nos slugs/tabelas anteriores.
    protected static ?string $slug = 'comissoes';

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?int $navigationSort = 26;

    /** Comissão é sempre gerada pelo sistema (ver ComissaoService) — nunca criada manualmente. */
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
        return ComissoesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageComissoes::route('/'),
        ];
    }
}
