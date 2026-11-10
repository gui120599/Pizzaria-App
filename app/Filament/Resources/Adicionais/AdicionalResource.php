<?php

namespace App\Filament\Resources\Adicionais;

use App\Enums\ProdutoTipoEnum;
use App\Filament\Resources\Adicionais\Pages\CreateAdicional;
use App\Filament\Resources\Adicionais\Pages\EditAdicional;
use App\Filament\Resources\Adicionais\Pages\ListAdicionais;
use App\Filament\Resources\Adicionais\RelationManagers\ProdutosRelationManager;
use App\Filament\Support\FaixaValorFilter;
use App\Models\Adicional;
use App\Models\Categoria;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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

                Section::make('Na cozinha')
                    ->description('Porção mostrada na ficha técnica do tablet quando o adicional entra no item.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('adicional_quantidade_porcao')
                            ->label('Porção')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.001)
                            ->placeholder('Ex.: 0,04'),
                        TextInput::make('adicional_unidade_porcao')
                            ->label('Unidade')
                            ->maxLength(10)
                            ->placeholder('KG, L, UN'),
                        Select::make('adicional_insumo_id')
                            ->label('Insumo')
                            ->relationship('insumo', 'produto_descricao', fn (Builder $query) => $query->where('produto_tipo', '!=', ProdutoTipoEnum::PRODUZIDO->value))
                            ->searchable(),
                        TextInput::make('adicional_instrucao_preparo')
                            ->label('Instrução de preparo')
                            ->placeholder('Ex.: espalhar depois do forno')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
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
                    // Sem foto vai null: o ImageColumn reprova a URL do padrão (tem espaço) e ficaria quebrada.
                    ->state(fn (Adicional $record): ?string => $record->temFoto() ? $record->getImagemUrl() : null)
                    ->defaultImageUrl(asset('Sem Imagem.png'))
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
                TernaryFilter::make('vinculado')
                    ->label('Vínculo com produtos')
                    ->placeholder('Todos')
                    ->trueLabel('Com produto vinculado')
                    ->falseLabel('Sem nenhum produto')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->has('produtos'),
                        false: fn (Builder $query): Builder => $query->doesntHave('produtos'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                SelectFilter::make('categoria_produto')
                    ->label('Vinculado a produtos da categoria')
                    ->modifyFormFieldUsing(fn (Select $select): Select => $select->helperText('Inclui as subcategorias.'))
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => Categoria::query()->orderBy('categoria_nome')->pluck('categoria_nome', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['values'] ?? null),
                        fn (Builder $q): Builder => $q->whereHas('produtos', fn (Builder $produtos): Builder => $produtos
                            ->whereIn('produto_categoria_id', Categoria::idsComSubcategorias($data['values']))),
                    )),

                FaixaValorFilter::make('adicional_valor'),

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

    public static function getRelations(): array
    {
        return [
            ProdutosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdicionais::route('/'),
            'create' => CreateAdicional::route('/create'),
            'edit' => EditAdicional::route('/{record}/edit'),
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
