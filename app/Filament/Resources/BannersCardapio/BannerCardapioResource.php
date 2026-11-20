<?php

namespace App\Filament\Resources\BannersCardapio;

use App\Filament\Resources\BannersCardapio\Pages\CreateBannerCardapio;
use App\Filament\Resources\BannersCardapio\Pages\EditBannerCardapio;
use App\Filament\Resources\BannersCardapio\Pages\ListBannersCardapio;
use App\Filament\Resources\BannersCardapio\Schemas\BannerCardapioForm;
use App\Filament\Resources\BannersCardapio\Tables\BannersCardapioTable;
use App\Models\BannerCardapio;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Carrossel de banners no topo do cardápio online (delivery e mesa). */
class BannerCardapioResource extends Resource
{
    protected static ?string $model = BannerCardapio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static UnitEnum|string|null $navigationGroup = 'Cardápio';

    protected static ?string $navigationLabel = 'Banners do cardápio';

    protected static ?string $modelLabel = 'banner';

    protected static ?string $pluralModelLabel = 'banners do cardápio';

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'banners-cardapio';

    protected static ?string $recordTitleAttribute = 'banner_titulo';

    /** O Filament poria "Banners Do Cardápio" (headline) no breadcrumb. */
    public static function getBreadcrumb(): string
    {
        return 'Banners do cardápio';
    }

    public static function form(Schema $schema): Schema
    {
        return BannerCardapioForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BannersCardapioTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBannersCardapio::route('/'),
            'create' => CreateBannerCardapio::route('/create'),
            'edit' => EditBannerCardapio::route('/{record}/edit'),
        ];
    }
}
