<?php

namespace App\Filament\Resources\OpcoesEntregas;

use App\Filament\Resources\OpcoesEntregas\Pages\ManageOpcoesEntregas;
use App\Models\OpcoesEntregas;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class OpcoesEntregasResource extends Resource
{
    protected static ?string $model = OpcoesEntregas::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Opções de Entrega';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'opcaoentrega_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SchemaSection::make('Identificação')
                ->icon('heroicon-o-truck')
                ->schema([
                    TextInput::make('opcaoentrega_nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Ex: Entrega, Retirada, Comer no Local'),

                    Toggle::make('opcaoentrega_requer_endereco')
                        ->label('Exige endereço de entrega')
                        ->helperText('Ative para opções como "Entrega" — o atendimento vai exigir rua e bairro preenchidos antes de salvar o pedido.'),
                ]),

            SchemaSection::make('Taxa de Entrega')
                ->icon('heroicon-o-banknotes')
                ->description('Configure a taxa cobrada quando o pedido estiver abaixo do valor mínimo.')
                ->schema([
                    TextInput::make('opcaoentrega_valor_frete')
                        ->label('Valor da Taxa (R$)')
                        ->numeric()
                        ->step(0.01)
                        ->prefix('R$')
                        ->default(0)
                        ->helperText('Deixe 0 para não cobrar taxa de entrega.'),

                    TextInput::make('opcaoentrega_min_valor_frete')
                        ->label('Pedido Mínimo para Isenção (R$)')
                        ->numeric()
                        ->step(0.01)
                        ->prefix('R$')
                        ->default(0)
                        ->helperText('Pedidos iguais ou acima deste valor não pagam taxa. Deixe 0 para sempre cobrar.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('opcaoentrega_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('opcaoentrega_valor_frete')
                    ->label('Taxa de Entrega')
                    ->money('BRL')
                    ->placeholder('Sem taxa'),

                TextColumn::make('opcaoentrega_min_valor_frete')
                    ->label('Mínimo p/ Isenção')
                    ->money('BRL')
                    ->placeholder('—'),

                IconColumn::make('opcaoentrega_requer_endereco')
                    ->label('Exige endereço')
                    ->boolean(),

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
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
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
            'index' => ManageOpcoesEntregas::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
