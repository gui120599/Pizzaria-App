<?php

namespace App\Filament\Resources\Escalas\Schemas;

use App\Enums\Ponto\TipoEscala;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EscalaForm
{
    /** Dia ISO (1 = segunda … 7 = domingo). */
    public const DIAS_SEMANA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Escala')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(80)
                        ->columnSpan(2),
                    Select::make('tipo')
                        ->label('Tipo')
                        ->options(TipoEscala::class)
                        ->default(TipoEscala::Fixa)
                        ->helperText('Por exceção: sem marcação vale a jornada. Livre: sem extra nem falta.')
                        ->required(),
                    TextInput::make('tolerancia_marcacao_min')
                        ->label('Tolerância por marcação')
                        ->numeric()->integer()->minValue(0)->maxValue(60)
                        ->suffix('min')->default(5)->required(),
                    TextInput::make('tolerancia_diaria_min')
                        ->label('Tolerância diária')
                        ->numeric()->integer()->minValue(0)->maxValue(120)
                        ->suffix('min')->default(10)->required(),
                    TextInput::make('intervalo_minimo_min')
                        ->label('Intervalo mínimo (jornada > 6 h)')
                        ->numeric()->integer()->minValue(0)
                        ->suffix('min')->default(60)->required(),
                ]),

            Section::make('Dias')
                ->description('Saída antes da entrada vira o dia (ex.: 17:30 às 00:30).')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('dias')
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn(false)
                        ->schema([
                            Select::make('dia_semana')
                                ->label('Dia')
                                ->options(self::DIAS_SEMANA)
                                ->distinct()
                                ->required(),
                            Toggle::make('folga')
                                ->label('Folga')
                                ->live()
                                ->inline(false),
                            TimePicker::make('entrada')
                                ->label('Entrada')
                                ->seconds(false)
                                ->disabled(fn (Get $get): bool => (bool) $get('folga'))
                                ->required(fn (Get $get): bool => ! $get('folga')),
                            TimePicker::make('saida')
                                ->label('Saída')
                                ->seconds(false)
                                ->disabled(fn (Get $get): bool => (bool) $get('folga'))
                                ->required(fn (Get $get): bool => ! $get('folga')),
                            TextInput::make('intervalo_minutos')
                                ->label('Intervalo')
                                ->numeric()->integer()->minValue(0)
                                ->suffix('min')
                                ->default(0)
                                ->disabled(fn (Get $get): bool => (bool) $get('folga')),
                        ])
                        ->columns(5)
                        ->maxItems(7)
                        ->reorderable(false)
                        ->addActionLabel('Adicionar dia')
                        ->itemLabel(fn (array $state): ?string => self::DIAS_SEMANA[(int) ($state['dia_semana'] ?? 0)] ?? null)
                        // Folga não guarda horário (desabilitado = não vai no state).
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizarDia($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizarDia($data))
                        ->default(fn (): array => collect(self::DIAS_SEMANA)
                            ->map(fn (string $nome, int $dia): array => [
                                'dia_semana' => $dia,
                                'folga' => $dia <= 2,
                                'entrada' => $dia <= 2 ? null : '17:30',
                                'saida' => $dia <= 2 ? null : '00:30',
                                'intervalo_minutos' => $dia <= 2 ? 0 : 60,
                            ])
                            ->values()
                            ->all()),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $dia
     * @return array<string, mixed>
     */
    private static function normalizarDia(array $dia): array
    {
        if ((bool) ($dia['folga'] ?? false)) {
            return [...$dia, 'entrada' => null, 'saida' => null, 'intervalo_minutos' => 0];
        }

        return $dia;
    }
}
