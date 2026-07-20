<?php

namespace App\Filament\Resources\HorarioFuncionamento;

use App\Filament\Resources\HorarioFuncionamento\Pages\ManageHorarioFuncionamento;
use App\Models\HorarioFuncionamento;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class HorarioFuncionamentoResource extends Resource
{
    protected static ?string $model = HorarioFuncionamento::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Horários de Funcionamento';

    protected static UnitEnum|string|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 10;

    protected static array $diasSemana = [
        0 => 'Domingo',
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SchemaSection::make('Horário')
                ->icon('heroicon-o-clock')
                ->columns(2)
                ->schema([
                    Select::make('horario_dia_semana')
                        ->label('Dia da Semana')
                        ->options(static::$diasSemana)
                        ->required()
                        ->columnSpanFull(),

                    TextInput::make('horario_abertura')
                        ->label('Abertura')
                        ->type('time')
                        ->required()
                        ->helperText('Horário de início do atendimento'),

                    TextInput::make('horario_fechamento')
                        ->label('Fechamento')
                        ->type('time')
                        ->required()
                        ->helperText('Horário de encerramento do atendimento'),

                    Toggle::make('horario_ativo')
                        ->label('Ativo')
                        ->default(true)
                        ->inline()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('horario_dia_semana')
            ->columns([
                TextColumn::make('horario_dia_semana')
                    ->label('Dia')
                    ->formatStateUsing(fn ($state) => static::$diasSemana[$state] ?? '—')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('horario_abertura')
                    ->label('Abertura')
                    ->formatStateUsing(fn ($state) => substr($state, 0, 5)),

                TextColumn::make('horario_fechamento')
                    ->label('Fechamento')
                    ->formatStateUsing(fn ($state) => substr($state, 0, 5)),

                ToggleColumn::make('horario_ativo')
                    ->label('Ativo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
                \Filament\Actions\BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageHorarioFuncionamento::route('/'),
        ];
    }
}
