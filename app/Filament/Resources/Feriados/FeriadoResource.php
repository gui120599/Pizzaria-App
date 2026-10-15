<?php

namespace App\Filament\Resources\Feriados;

use App\Enums\Ponto\AbrangenciaFeriado;
use App\Filament\Resources\Feriados\Pages\ManageFeriados;
use App\Models\Feriado;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Feriados (dia trabalhado vira extra 100%). Ponto facultativo não muda a apuração. */
class FeriadoResource extends Resource
{
    protected static ?string $model = Feriado::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static UnitEnum|string|null $navigationGroup = 'Ponto e Repasses';

    protected static ?string $navigationLabel = 'Feriados';

    protected static ?string $modelLabel = 'feriado';

    protected static ?string $pluralModelLabel = 'Feriados';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('nome')->label('Nome')->required()->maxLength(80),
            DatePicker::make('data')->label('Data')->native(false)->displayFormat('d/m/Y')->required(),
            Select::make('abrangencia')->label('Abrangência')->options(AbrangenciaFeriado::class)->default(AbrangenciaFeriado::Municipal)->required(),
            Toggle::make('recorrente_anual')->label('Repete todo ano no mesmo dia')->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('data')
            ->columns([
                TextColumn::make('data')->label('Data')->date('d/m/Y')->sortable()
                    ->description(fn (Feriado $record): ?string => $record->recorrente_anual ? 'Todo ano' : null),
                TextColumn::make('nome')->label('Nome')->searchable(),
                TextColumn::make('abrangencia')->label('Abrangência')->badge(),
                IconColumn::make('recorrente_anual')->label('Recorrente')->boolean()->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('abrangencia')->label('Abrangência')->options(AbrangenciaFeriado::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeriados::route('/'),
        ];
    }
}
