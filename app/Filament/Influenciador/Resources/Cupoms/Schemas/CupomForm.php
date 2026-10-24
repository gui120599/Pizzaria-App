<?php

namespace App\Filament\Influenciador\Resources\Cupoms\Schemas;

use App\Enums\TipoDescontoEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CupomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados do Cupom')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(6)
                            ->schema([
                                TextInput::make('cupom_codigo')
                                    ->label('Código')
                                    ->placeholder('Ex: JOAO10')
                                    ->required()
                                    ->maxLength(50)
                                    ->unique(ignoreRecord: true)
                                    ->columnSpan(2),

                                Select::make('cupom_tipo_desconto')
                                    ->label('Tipo de Desconto')
                                    ->options([
                                        TipoDescontoEnum::PERCENTUAL->value => TipoDescontoEnum::PERCENTUAL->label(),
                                        TipoDescontoEnum::VALOR_FIXO->value => TipoDescontoEnum::VALOR_FIXO->label(),
                                    ])
                                    ->required()
                                    ->native(false)
                                    ->columnSpan(2),

                                TextInput::make('cupom_valor_desconto')
                                    ->label('Valor do Desconto')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->columnSpan(2),

                                TextInput::make('cupom_valor_minimo_pedido')
                                    ->label('Valor Mínimo do Pedido')
                                    ->numeric()
                                    ->minValue(0)
                                    ->helperText('Deixe em branco para não exigir valor mínimo.')
                                    ->columnSpan(2),

                                TextInput::make('cupom_limite_uso_total')
                                    ->label('Limite Total de Usos')
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText('Deixe em branco para uso ilimitado.')
                                    ->columnSpan(2),

                                TextInput::make('cupom_limite_uso_por_cliente')
                                    ->label('Limite de Usos por Cliente')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->columnSpan(2),

                                DatePicker::make('cupom_data_inicio')
                                    ->label('Início da Vigência')
                                    ->displayFormat('d/m/Y')
                                    ->native(false)
                                    ->columnSpan(2),

                                DatePicker::make('cupom_data_expiracao')
                                    ->label('Expiração')
                                    ->displayFormat('d/m/Y')
                                    ->native(false)
                                    ->afterOrEqual('cupom_data_inicio')
                                    ->columnSpan(2),

                                Toggle::make('cupom_ativo')
                                    ->label('Ativo')
                                    ->default(true)
                                    ->columnSpan(2),
                            ]),
                    ]),
            ])
            ->columns(6);
    }
}
