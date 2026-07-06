<?php

namespace App\Filament\Resources\CentroCustos;

use App\Filament\Resources\CentroCustos\Pages\ManageCentroCustos;
use App\Models\CentroCusto;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CentroCustoResource extends Resource
{
    protected static ?string $model = CentroCusto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'Centros de Custo';

    protected static ?string $modelLabel = 'Centro de Custo';

    protected static ?string $pluralModelLabel = 'Centros de Custo';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'centro_custo_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('centro_custo_nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(100)
                    ->placeholder('Ex.: Cozinha, Bar, Delivery'),

                Select::make('centro_custo_tipo')
                    ->label('Tipo')
                    ->options([
                        'operacional' => 'Operacional',
                        'perda' => 'Perda / Quebra',
                        'consumo_interno' => 'Consumo Interno',
                        'administrativo' => 'Administrativo',
                    ])
                    ->native(false)
                    ->placeholder('Opcional'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('centro_custo_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('centro_custo_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('movimentacoes_count')
                    ->label('Movimentações')
                    ->counts('movimentacoes')
                    ->badge()
                    ->color('gray'),
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
            'index' => ManageCentroCustos::route('/'),
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
