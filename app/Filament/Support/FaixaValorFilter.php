<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtro "valor de ... até ..." sobre uma coluna monetária.
 *
 * Usa TextInput numérico, como o filtro de preço do ProdutosTable, e não o
 * Money do pacote ptbr: o Money preenche o campo vazio com "0,00", o que
 * num "até" esconderia tudo.
 */
class FaixaValorFilter
{
    public static function make(string $coluna, string $nome = 'faixa_valor', string $label = 'Valor'): Filter
    {
        return Filter::make($nome)
            ->label($label)
            ->schema([
                TextInput::make('valor_min')
                    ->label("{$label} de")
                    ->prefix('R$')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    // Nas tabelas que filtram sem botão "Aplicar", espera parar de digitar.
                    ->live(debounce: 600),
                TextInput::make('valor_max')
                    ->label("{$label} até")
                    ->prefix('R$')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    // Nas tabelas que filtram sem botão "Aplicar", espera parar de digitar.
                    ->live(debounce: 600),
            ])
            ->columns(2)
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when(filled($data['valor_min'] ?? null), fn (Builder $q): Builder => $q->where($coluna, '>=', (float) $data['valor_min']))
                ->when(filled($data['valor_max'] ?? null), fn (Builder $q): Builder => $q->where($coluna, '<=', (float) $data['valor_max'])))
            ->indicateUsing(fn (array $data): array => array_values(array_filter([
                filled($data['valor_min'] ?? null) ? "{$label} a partir de ".self::formatar($data['valor_min']) : null,
                filled($data['valor_max'] ?? null) ? "{$label} até ".self::formatar($data['valor_max']) : null,
            ])));
    }

    private static function formatar(mixed $valor): string
    {
        return 'R$ '.number_format((float) $valor, 2, ',', '.');
    }
}
