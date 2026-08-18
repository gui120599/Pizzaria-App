<?php

namespace App\Filament\Resources\Mesas;

use App\Enums\StatusMesa;
use App\Filament\Resources\Mesas\Pages\CreateMesa;
use App\Filament\Resources\Mesas\Pages\EditMesa;
use App\Filament\Resources\Mesas\Pages\ListMesas;
use App\Filament\Resources\Mesas\RelationManagers\SessoesRelationManager;
use App\Models\Mesa;
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

class MesaResource extends Resource
{
    protected static ?string $model = Mesa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static UnitEnum|string|null $navigationGroup = 'Salão';

    protected static ?string $navigationLabel = 'Mesas';

    protected static ?string $modelLabel = 'mesa';

    protected static ?string $pluralModelLabel = 'mesas';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'mesa_nome';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('mesa_nome')
                ->label('Nome')
                ->required()
                ->maxLength(100)
                ->placeholder('Ex.: Mesa 01, Varanda 2'),

            Select::make('mesa_status')
                ->label('Status')
                ->options(StatusMesa::class)
                ->default(StatusMesa::Liberada->value)
                ->required()
                ->native(false)
                ->helperText('O status normalmente é controlado automaticamente pela abertura/fechamento de sessão. Altere aqui só pra correção administrativa.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('mesa_nome')
            ->defaultSort('mesa_nome')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('sessoes'))
            ->columns([
                TextColumn::make('mesa_nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('mesa_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusMesa::from($state)->getLabel())
                    ->color(fn (string $state): string => StatusMesa::from($state)->getColor()),

                TextColumn::make('sessoes_count')
                    ->label('Sessões')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Criada em')
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

    public static function getRelations(): array
    {
        return [
            SessoesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMesas::route('/'),
            'create' => CreateMesa::route('/create'),
            'edit' => EditMesa::route('/{record}/edit'),
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
