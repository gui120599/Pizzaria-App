<?php

namespace App\Filament\Resources\Fornecedores;

use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Filament\Resources\Fornecedores\Pages\CreateFornecedor;
use App\Filament\Resources\Fornecedores\Pages\EditFornecedor;
use App\Filament\Resources\Fornecedores\Pages\ListFornecedores;
use App\Filament\Resources\Fornecedores\RelationManagers\FornecedorProdutosRelationManager;
use App\Models\Prestador;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FornecedorResource extends Resource
{
    protected static ?string $model = Prestador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Fornecedores';

    protected static ?string $modelLabel = 'Fornecedor';

    protected static ?string $pluralModelLabel = 'Fornecedores';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'fornecedores';

    protected static ?string $recordTitleAttribute = 'nome';

    /** Escopo: apenas prestadores da categoria Fornecedor. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('categoria', PrestadorCategoriaEnum::FORNECEDOR);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')
                ->columns(2)
                ->schema([
                    Select::make('tipo')
                        ->label('Tipo')
                        ->options(collect(PrestadorTipoEnum::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->toArray())
                        ->default(PrestadorTipoEnum::PJ->value)
                        ->native(false)
                        ->required(),

                    TextInput::make('nome')->label('Nome / Apelido')->required()->maxLength(255),
                    TextInput::make('razao_social')->label('Razão Social')->maxLength(255),
                    TextInput::make('nome_fantasia')->label('Nome Fantasia')->maxLength(255),
                    TextInput::make('cpf_cnpj')->label('CPF / CNPJ')->maxLength(20),
                    TextInput::make('inscricao_estadual')->label('Inscrição Estadual')->maxLength(30),
                ]),

            Section::make('Contato')
                ->columns(3)
                ->schema([
                    TextInput::make('email')->label('E-mail')->email()->maxLength(255),
                    TextInput::make('telefone')->label('Telefone')->maxLength(20),
                    TextInput::make('celular')->label('Celular')->maxLength(20),
                ]),

            Section::make('Endereço')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextInput::make('cep')->label('CEP')->maxLength(10),
                    TextInput::make('endereco')->label('Endereço')->columnSpan(2),
                    TextInput::make('numero')->label('Número'),
                    TextInput::make('complemento')->label('Complemento'),
                    TextInput::make('bairro')->label('Bairro'),
                    TextInput::make('cidade')->label('Cidade'),
                    TextInput::make('uf')->label('UF')->maxLength(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('fornecedorProdutos'))
            ->columns([
                TextColumn::make('nome')->label('Nome')->searchable(['nome', 'razao_social', 'nome_fantasia'])->sortable(),
                TextColumn::make('cpf_cnpj')->label('CPF/CNPJ')->searchable()->placeholder('—'),
                TextColumn::make('cidade')->label('Cidade')->placeholder('—')->toggleable(),
                TextColumn::make('telefone')->label('Telefone')->placeholder('—')->toggleable(),
                TextColumn::make('fornecedor_produtos_count')->label('Itens vinculados')->badge()->color('gray'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            FornecedorProdutosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFornecedores::route('/'),
            'create' => CreateFornecedor::route('/create'),
            'edit' => EditFornecedor::route('/{record}/edit'),
        ];
    }
}
