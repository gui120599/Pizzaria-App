<?php

namespace App\Filament\Influenciador\Resources\Cupoms;

use App\Filament\Influenciador\Resources\Cupoms\Pages\CreateCupom;
use App\Filament\Influenciador\Resources\Cupoms\Pages\EditCupom;
use App\Filament\Influenciador\Resources\Cupoms\Pages\ListCupoms;
use App\Filament\Influenciador\Resources\Cupoms\Schemas\CupomForm;
use App\Filament\Influenciador\Resources\Cupoms\Tables\CupomsTable;
use App\Models\Cupom;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CupomResource extends Resource
{
    protected static ?string $model = Cupom::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Meus Cupons';

    protected static ?string $recordTitleAttribute = 'cupom_codigo';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('cupom_influenciador_id', Auth::guard('influenciador')->id());
    }

    public static function form(Schema $schema): Schema
    {
        return CupomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CupomsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCupoms::route('/'),
            'create' => CreateCupom::route('/create'),
            'edit' => EditCupom::route('/{record}/edit'),
        ];
    }
}
