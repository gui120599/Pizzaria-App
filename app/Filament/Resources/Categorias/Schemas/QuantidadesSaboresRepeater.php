<?php

namespace App\Filament\Resources\Categorias\Schemas;

use App\Models\QuantidadeSabor;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Opções de quantidade de sabores da categoria ("Sabor único", "Meia a meia",
 * "3 sabores"), editadas dentro do modal da categoria — a CategoriaResource é
 * simples (ManageCategorias), sem página de edição para um RelationManager.
 *
 * Cada opção define o percentual de cada posição de sabor, usado na baixa de
 * estoque e no rateio de adicionais (soma sempre 100). O último percentual
 * nunca é digitado: é sempre 100 menos a soma dos anteriores.
 */
class QuantidadesSaboresRepeater
{
    /**
     * Teto de sabores suportado pelo form — nenhuma pizza real passa disso;
     * evita renderizar dezenas de campos.
     */
    public const MAX_SABORES = 6;

    public static function make(): Repeater
    {
        return Repeater::make('quantidadesSabores')
            ->label('Quantidades de sabores')
            ->relationship()
            ->orderColumn('quantidade_sabor_ordem')
            ->schema([
                TextInput::make('quantidade_sabor_descricao')
                    ->label('Nome da opção')
                    ->placeholder('Ex.: Sabor único, Meia a meia, 3 sabores')
                    ->required()
                    ->maxLength(60),

                TextInput::make('quantidade_sabor_quantidade')
                    ->label('Quantidade de sabores')
                    ->helperText('1 = sabor único.')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(self::MAX_SABORES)
                    ->required()
                    ->distinct()
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        $percentuais = QuantidadeSabor::percentuaisIguais(min(self::MAX_SABORES, max(1, (int) $state)));
                        $set('quantidade_sabor_percentuais', $percentuais);
                        $set('quantidade_sabor_rotulos', QuantidadeSabor::rotulosPadrao($percentuais, 'extenso'));
                    }),

                Grid::make(3)
                    ->schema(self::camposPercentuais())
                    ->columnSpanFull(),

                // Atalho: preenche as descrições de todas as posições de uma
                // vez; depois cada uma pode ser editada ou apagada. Só de UI —
                // o que é gravado são as descrições.
                ToggleButtons::make('nomenclatura')
                    ->label('Nomenclatura dos sabores')
                    ->helperText('Preenche as descrições abaixo. Exibidas antes do nome do sabor nos pedidos e impressões.')
                    ->options([
                        'extenso' => 'Por extenso (MEIA, TERÇO)',
                        'fracao' => 'Fração (½, ⅓)',
                        'nenhuma' => 'Nenhuma',
                    ])
                    ->inline()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if ($state === null) {
                            return;
                        }

                        $quantidade = (int) ($get('quantidade_sabor_quantidade') ?? 1);
                        $percentuais = array_map(
                            fn (int $i) => (float) ($get("quantidade_sabor_percentuais.{$i}") ?? 0),
                            range(0, max(1, $quantidade) - 1),
                        );

                        $set('quantidade_sabor_rotulos', QuantidadeSabor::rotulosPadrao($percentuais, $state));
                    })
                    ->columnSpanFull(),

                Grid::make(3)
                    ->schema(self::camposRotulos())
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->itemLabel(fn (array $state): ?string => $state['quantidade_sabor_descricao'] ?? null)
            ->collapsible()
            ->defaultItems(0)
            ->addActionLabel('Adicionar opção')
            ->columnSpanFull();
    }

    /**
     * Um TextInput fixo por posição, visível só até a quantidade escolhida. O
     * último visível é sempre o resto até 100 — calculado, nunca editável.
     *
     * @return array<int, TextInput>
     */
    private static function camposPercentuais(): array
    {
        return collect(range(0, self::MAX_SABORES - 1))
            ->map(function (int $indice): TextInput {
                $ehUltimo = fn (Get $get): bool => $indice === (int) ($get('quantidade_sabor_quantidade') ?? 1) - 1;

                return TextInput::make("quantidade_sabor_percentuais.{$indice}")
                    ->label('Sabor '.($indice + 1))
                    ->numeric()
                    ->suffix('%')
                    ->step(0.01)
                    ->minValue(0)
                    ->maxValue(100)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => (int) ($get('quantidade_sabor_quantidade') ?? 0) > $indice)
                    ->disabled($ehUltimo)
                    ->dehydrated()
                    ->helperText(fn (Get $get): ?string => $ehUltimo($get) ? 'Calculado (resto até 100%).' : null)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get, $indice): void {
                            $quantidade = (int) ($get('quantidade_sabor_quantidade') ?? 1);

                            if ($indice >= $quantidade - 1) {
                                return;
                            }

                            if (self::somaEditaveis($get, $quantidade) > 100) {
                                $fail('A soma dos percentuais dos sabores não pode ultrapassar 100%.');
                            }
                        },
                    ])
                    ->afterStateUpdated(function (Get $get, Set $set): void {
                        $quantidade = (int) ($get('quantidade_sabor_quantidade') ?? 1);
                        $set('quantidade_sabor_percentuais.'.($quantidade - 1), round(100 - self::somaEditaveis($get, $quantidade), 2));
                    });
            })
            ->all();
    }

    /**
     * Descrição livre de cada posição ("MEIA", "½", "1ª METADE" ou vazia =
     * só o nome do sabor). Registro sem descrição salva mostra o padrão por
     * extenso — o mesmo que sai nas telas hoje.
     *
     * @return array<int, TextInput>
     */
    private static function camposRotulos(): array
    {
        return collect(range(0, self::MAX_SABORES - 1))
            ->map(fn (int $indice): TextInput => TextInput::make("quantidade_sabor_rotulos.{$indice}")
                ->label('Descrição do sabor '.($indice + 1))
                ->placeholder('Vazio = só o nome do sabor')
                ->maxLength(20)
                ->visible(fn (Get $get): bool => (int) ($get('quantidade_sabor_quantidade') ?? 0) > $indice)
                ->afterStateHydrated(function (TextInput $component, $state, Get $get) use ($indice): void {
                    if ($state === null) {
                        $component->state(QuantidadeSabor::rotulosPadrao(
                            [(float) ($get("quantidade_sabor_percentuais.{$indice}") ?? 0)],
                            'extenso',
                        )[0]);
                    }
                })
                // Vazio precisa chegar ao banco como "" (= nenhuma descrição):
                // null cairia de volta no padrão por extenso.
                ->dehydrateStateUsing(fn ($state): string => trim((string) $state)))
            ->all();
    }

    private static function somaEditaveis(Get $get, int $quantidade): float
    {
        $soma = 0.0;

        for ($i = 0; $i < $quantidade - 1; $i++) {
            $soma += (float) ($get("quantidade_sabor_percentuais.{$i}") ?? 0);
        }

        return $soma;
    }
}
