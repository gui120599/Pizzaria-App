<?php

namespace App\Filament\Resources\Cupons\Schemas;

use App\Enums\OrigemCupomEnum;
use App\Enums\TipoDescontoEnum;
use App\Models\Cupom;
use App\Models\Empresa;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CupomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('cupom_codigo')
                    ->label('Código')
                    ->required()
                    ->maxLength(50)
                    ->dehydrateStateUsing(fn (?string $state): string => Cupom::normalizarCodigo((string) $state))
                    ->unique(ignoreRecord: true)
                    ->helperText('Sem espaços; é gravado em maiúsculas.'),

                Select::make('cupom_tipo_desconto')
                    ->label('Tipo de desconto')
                    ->options([
                        TipoDescontoEnum::PERCENTUAL->value => TipoDescontoEnum::PERCENTUAL->label(),
                        TipoDescontoEnum::VALOR_FIXO->value => TipoDescontoEnum::VALOR_FIXO->label(),
                    ])
                    ->required()
                    ->native(false)
                    ->live()
                    ->disabled(fn (?Cupom $record): bool => (bool) $record?->jaFoiUsado()),

                TextInput::make('cupom_valor_desconto')
                    ->label('Valor do desconto')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(fn (Get $get, ?Cupom $record): ?float => self::teto($record, $get('cupom_tipo_desconto')))
                    ->helperText(fn (?Cupom $record): ?string => $record?->jaFoiUsado()
                        ? 'O cupom já foi usado: tipo e valor não podem mais mudar.'
                        : ($record?->cupom_origem === OrigemCupomEnum::INFLUENCIADOR ? 'Cupom de influenciador: vale o teto da configuração.' : null))
                    ->required()
                    ->disabled(fn (?Cupom $record): bool => (bool) $record?->jaFoiUsado()),

                TextInput::make('cupom_valor_minimo_pedido')
                    ->label('Valor mínimo do pedido')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Deixe em branco para não exigir valor mínimo.'),

                TextInput::make('cupom_limite_uso_total')
                    ->label('Limite total de usos')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(fn (?Cupom $record): ?int => $record?->cupom_origem === OrigemCupomEnum::INFLUENCIADOR
                        ? Empresa::first()?->empresa_influ_teto_usos_total
                        : null)
                    ->helperText('Deixe em branco para uso ilimitado.'),

                TextInput::make('cupom_limite_uso_por_cliente')
                    ->label('Limite de usos por cliente')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),

                DatePicker::make('cupom_data_inicio')
                    ->label('Início da vigência')
                    ->displayFormat('d/m/Y')
                    ->native(false),

                DatePicker::make('cupom_data_expiracao')
                    ->label('Expiração')
                    ->displayFormat('d/m/Y')
                    ->native(false)
                    ->afterOrEqual('cupom_data_inicio'),

                Toggle::make('cupom_ativo')
                    ->label('Ativo')
                    ->default(true),
            ])
            ->columns(2);
    }

    /** Teto só vale para cupom de influenciador; o interno não tem. */
    private static function teto(?Cupom $record, ?string $tipo): ?float
    {
        if ($record?->cupom_origem !== OrigemCupomEnum::INFLUENCIADOR) {
            return null;
        }

        $empresa = Empresa::first();

        return $tipo === TipoDescontoEnum::PERCENTUAL->value
            ? (float) ($empresa?->empresa_influ_teto_percentual ?? 100)
            : (float) ($empresa?->empresa_influ_teto_valor_fixo ?? 999999);
    }
}
