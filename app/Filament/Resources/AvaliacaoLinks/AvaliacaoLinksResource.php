<?php

namespace App\Filament\Resources\AvaliacaoLinks;

use App\Filament\Resources\AvaliacaoLinks\Pages\ManageAvaliacaoLinks;
use App\Models\AvaliacaoLink;
use BackedEnum;
use UnitEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AvaliacaoLinksResource extends Resource
{
    protected static ?string $model = AvaliacaoLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Links de Avaliação';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'avaliacao_link_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SchemaSection::make('Identificação')
                ->icon('heroicon-o-star')
                ->schema([
                    TextInput::make('avaliacao_link_nome')
                        ->label('Nome da Plataforma')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Ex: Google Maps, Waze, Apple Maps...'),

                    TextInput::make('avaliacao_link_url')
                        ->label('URL da Avaliação')
                        ->required()
                        ->url()
                        ->maxLength(500)
                        ->placeholder('https://...'),

                    FileUpload::make('avaliacao_link_logo_url')
                        ->label('Logo da Plataforma')
                        ->disk('public')
                        ->directory('avaliacao_logos')
                        ->image()
                        ->required()
                        ->helperText('Formatos: PNG, SVG, JPG. Recomendado: fundo transparente.'),
                ]),

            SchemaSection::make('Exibição')
                ->icon('heroicon-o-eye')
                ->schema([
                    Toggle::make('avaliacao_link_ativo')
                        ->label('Ativo no Cardápio')
                        ->default(true),

                    TextInput::make('avaliacao_link_ordem')
                        ->label('Ordem de Exibição')
                        ->numeric()
                        ->integer()
                        ->default(0)
                        ->helperText('Menor número aparece primeiro.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avaliacao_link_logo_url')
                    ->label('Logo')
                    ->disk('public')
                    ->width(40)
                    ->height(40)
                    ->extraImgAttributes(['class' => 'object-contain']),

                TextColumn::make('avaliacao_link_nome')
                    ->label('Plataforma')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('avaliacao_link_url')
                    ->label('URL')
                    ->limit(50)
                    ->url(fn (AvaliacaoLink $record) => $record->avaliacao_link_url)
                    ->openUrlInNewTab()
                    ->color('info'),

                ToggleColumn::make('avaliacao_link_ativo')
                    ->label('Ativo'),

                TextColumn::make('avaliacao_link_ordem')
                    ->label('Ordem')
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('avaliacao_link_ordem')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\CreateAction::make(),
                \Filament\Actions\BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAvaliacaoLinks::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
