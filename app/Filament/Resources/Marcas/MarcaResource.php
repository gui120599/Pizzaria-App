<?php

namespace App\Filament\Resources\Marcas;

use App\Filament\Resources\Marcas\Pages\ManageMarcas;
use App\Models\Marca;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class MarcaResource extends Resource
{
    protected static ?string $model = Marca::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Marcas';

    protected static ?string $modelLabel = 'Marca';

    protected static ?string $pluralModelLabel = 'Marcas';

    protected static ?int $navigationSort = 55;

    protected static ?string $recordTitleAttribute = 'marca_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('marca_nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('Ex.: Sadia, Perdigão, Tirolez'),

                FileUpload::make('marca_imagem')
                    ->label('Imagem')
                    ->disk('public')
                    ->directory('marcas')
                    ->image()
                    ->imageEditor()
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeTargetWidth(300)
                    ->imageResizeTargetHeight(300)
                    ->helperText('Usada para identificar a marca nos seletores de compra/balanço/lote.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('marca_nome')
            ->defaultSort('marca_nome')
            ->columns([
                ImageColumn::make('marca_imagem')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(44)
                    ->defaultImageUrl(asset('Sem Imagem.png')),

                TextColumn::make('marca_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('itens_compra_count')
                    ->label('Itens de compra')
                    ->counts('itensCompra')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('lotes_count')
                    ->label('Lotes')
                    ->counts('lotes')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMarcas::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
