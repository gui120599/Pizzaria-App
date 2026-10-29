<?php

namespace App\Filament\Support;

use App\Enums\OperadoraMaquininha;
use App\Filament\Components\ContaFinanceiraSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Campos de cadastro de Maquininha, reaproveitados no createOptionForm inline
 * dos repeaters de abertura/fechamento de caixa E no MaquininhaResource — uma
 * única fonte, evita os dois repeaters divergirem de novo.
 */
final class MaquininhaQuickCreateForm
{
    public static function schema(): array
    {
        return [
            TextInput::make('nome')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            Select::make('operadora')
                ->label('Operadora')
                ->options(OperadoraMaquininha::class)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    if (! self::ehStone($state)) {
                        $set('maquininha_connect_ativo', false);
                    }
                }),
            TextInput::make('numero_serie')
                ->label('Número de série')
                ->maxLength(255),
            Toggle::make('maquininha_connect_ativo')
                ->label('Recebe pedidos Stone Connect')
                ->helperText('Ligado, o PDV, o atendimento, o painel de pedidos e o entregador podem enviar cobranças para esta maquininha (exige o número de série). Desligado, ela só serve para lançamento manual — use em maquininhas Stone que não integram ao Connect.')
                ->default(false)
                // disabled + dehydrated (e não visible): campo oculto não é gravado, e o flag antigo ficaria no banco.
                ->disabled(fn (Get $get): bool => ! self::ehStone($get('operadora')))
                ->dehydrated()
                ->dehydrateStateUsing(fn (mixed $state, Get $get): bool => (bool) $state && self::ehStone($get('operadora')))
                ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get): void {
                    if ($value && blank($get('numero_serie'))) {
                        $fail('Informe o número de série para receber pedidos Stone Connect.');
                    }
                }),
            ContaFinanceiraSelect::make('conta_financeira_id')
                ->label('Conta onde cai o dinheiro')
                ->semCaixaPdv()
                ->placeholder('Selecione a conta')
                ->helperText('Conta em que a adquirente liquida os recebimentos desta maquininha. Sem ela, o fechamento de caixa não é confirmado.'),
            Toggle::make('maquininha_padrao')
                ->label('Maquininha padrão')
                ->helperText('Usada para achar a taxa quando o pagamento não informa a maquininha.'),
        ];
    }

    /** O estado do Select chega como string ou, ao editar um registro, como o enum já convertido. */
    private static function ehStone(mixed $operadora): bool
    {
        return ($operadora instanceof OperadoraMaquininha ? $operadora : OperadoraMaquininha::tryFrom((string) $operadora)) === OperadoraMaquininha::Stone;
    }
}
