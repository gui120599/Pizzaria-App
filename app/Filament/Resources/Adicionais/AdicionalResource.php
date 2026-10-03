<?php

namespace App\Filament\Resources\Adicionais;

use App\Filament\Resources\Adicionais\Pages\ManageAdicionais;
use App\Models\Adicional;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
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
use Leandrocfe\FilamentPtbrFormFields\Money;
use UnitEnum;

class AdicionalResource extends Resource
{
    protected static ?string $model = Adicional::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static UnitEnum|string|null $navigationGroup = 'Cardápio';

    protected static ?string $navigationLabel = 'Adicionais';

    protected static ?string $modelLabel = 'Adicional';

    protected static ?string $pluralModelLabel = 'Adicionais';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'adicional_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('adicional_nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Ex.: Borda de catupiry, Bacon extra'),

                Money::make('adicional_valor')
                    ->label('Valor')
                    ->required()
                    ->minValue(0),

                FileUpload::make('adicional_foto')
                    ->label('Foto')
                    ->disk('public')
                    ->directory('fotos_adicionais')
                    ->image()
                    ->imageEditor()
                    ->imageResizeMode('cover')
                    ->imageCropAspectRatio('1:1')
                    ->imageResizeTargetWidth(300)
                    ->imageResizeTargetHeight(300)
                    // Fotos antigas (só o nome, em public/img/fotos_adicionais) não existem
                    // no disk public: sem isso o Filament descarta o valor ao salvar.
                    ->fetchFileInformation(false)
                    ->columnSpanFull()
                    ->helperText('JPG ou PNG, quadrada (1:1).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('adicional_nome')
            ->defaultSort('adicional_nome')
            ->columns([
                ImageColumn::make('adicional_foto')
                    ->label('')
                    ->state(fn (Adicional $record): string => $record->getImagemUrl())
                    ->circular()
                    ->size(44),

                TextColumn::make('adicional_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('adicional_valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),

                TextColumn::make('produtos_count')
                    ->label('Produtos')
                    ->counts('produtos')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make()
                    ->label('Inativos'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Inativar'),
                RestoreAction::make()
                    ->label('Reativar'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Inativar selecionados'),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make()
                        ->label('Reativar selecionados'),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdicionais::route('/'),
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
