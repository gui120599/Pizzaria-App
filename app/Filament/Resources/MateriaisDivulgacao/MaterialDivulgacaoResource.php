<?php

namespace App\Filament\Resources\MateriaisDivulgacao;

use App\Enums\TipoMaterialEnum;
use App\Filament\Resources\MateriaisDivulgacao\Pages\ManageMateriaisDivulgacao;
use App\Models\MaterialDivulgacao;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MaterialDivulgacaoResource extends Resource
{
    protected static ?string $model = MaterialDivulgacao::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Kit de Divulgação';

    protected static ?string $slug = 'materiais-divulgacao';

    protected static UnitEnum|string|null $navigationGroup = 'Pessoas';

    protected static ?int $navigationSort = 28;

    protected static ?string $recordTitleAttribute = 'material_titulo';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('material_titulo')
                    ->label('Título')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Textarea::make('material_descricao')
                    ->label('Descrição')
                    ->columnSpanFull(),

                Select::make('material_tipo')
                    ->label('Tipo')
                    ->options([
                        TipoMaterialEnum::IMAGEM->value => TipoMaterialEnum::IMAGEM->label(),
                        TipoMaterialEnum::VIDEO->value => TipoMaterialEnum::VIDEO->label(),
                        TipoMaterialEnum::TEXTO->value => TipoMaterialEnum::TEXTO->label(),
                    ])
                    ->required()
                    ->native(false)
                    ->live(),

                FileUpload::make('material_arquivo_path')
                    ->label('Arquivo')
                    ->disk('public')
                    ->directory('materiais_divulgacao')
                    ->visible(fn (callable $get): bool => in_array($get('material_tipo'), [
                        TipoMaterialEnum::IMAGEM->value,
                        TipoMaterialEnum::VIDEO->value,
                    ], true))
                    ->columnSpanFull(),

                Textarea::make('material_conteudo_texto')
                    ->label('Legenda pronta')
                    ->helperText('Use {codigo} onde quiser que o código do cupom do influenciador entre.')
                    ->columnSpanFull(),

                Toggle::make('material_ativo')
                    ->label('Ativo')
                    ->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('material_titulo')
            ->reorderable('material_ordem')
            ->defaultSort('material_ordem')
            ->emptyStateHeading('Nenhum material de divulgação cadastrado')
            ->columns([
                TextColumn::make('material_titulo')
                    ->label('Título')
                    ->searchable(),

                TextColumn::make('material_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),

                IconColumn::make('material_ativo')
                    ->label('Ativo')
                    ->boolean(),

                TextColumn::make('material_ordem')
                    ->label('Ordem')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMateriaisDivulgacao::route('/'),
        ];
    }
}
