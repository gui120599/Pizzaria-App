<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Leandrocfe\FilamentPtbrFormFields\Money;

/**
 * Campos de uma pergunta do item ("Escolha a borda") e das opções dela —
 * os mesmos na aba Perguntas do produto e no modal da categoria.
 */
class PerguntasSchema
{
    /**
     * @param  bool  $aninhado  true quando a pergunta já está dentro de um Repeater (modal da categoria)
     * @return array<int, mixed>
     */
    public static function campos(bool $aninhado = false): array
    {
        return [
            TextInput::make('pergunta_texto')
                ->label('Pergunta')
                ->placeholder('Ex.: Escolha a borda, Ponto da carne')
                ->required()
                ->maxLength(120)
                ->columnSpanFull(),

            Grid::make(3)
                ->schema([
                    TextInput::make('pergunta_minimo')
                        ->label('Mínimo de escolhas')
                        ->helperText('0 = opcional; 1 ou mais = obrigatória.')
                        ->integer()
                        ->minValue(0)
                        ->maxValue(20)
                        ->default(1)
                        ->required(),

                    TextInput::make('pergunta_maximo')
                        ->label('Máximo de escolhas')
                        ->helperText('1 = escolha única.')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(20)
                        ->default(1)
                        ->gte('pergunta_minimo')
                        ->required(),

                    Toggle::make('pergunta_ativa')
                        ->label('Ativa')
                        ->default(true)
                        ->inline(false),
                ])
                ->columnSpanFull(),

            self::opcoes($aninhado),
        ];
    }

    /**
     * Opções da pergunta. Dentro de outro Repeater o valor usa TextInput
     * numérico: o Money do leandrocfe/filament-ptbr-form-fields quebra num
     * Repeater aninhado ("$container must not be accessed before
     * initialization" — mesmo caso do PromocaoAdicionalForm).
     */
    private static function opcoes(bool $aninhado): Repeater
    {
        $valor = $aninhado
            ? TextInput::make('pergunta_opcao_valor')
                ->label('Acréscimo')
                ->helperText('Por unidade (ex.: 10.00). 0 = sem custo.')
                ->numeric()
                ->prefix('R$')
                ->step(0.01)
                ->minValue(0)
                ->default(0)
                ->required()
            : Money::make('pergunta_opcao_valor')
                ->label('Acréscimo')
                ->helperText('Por unidade. 0 = sem custo.')
                ->minValue(0)
                ->default('0,00')
                ->required();

        return Repeater::make('opcoes')
            ->label('Opções')
            ->relationship()
            ->orderColumn('pergunta_opcao_ordem')
            ->schema([
                TextInput::make('pergunta_opcao_nome')
                    ->label('Opção')
                    ->placeholder('Ex.: Catupiry')
                    ->required()
                    ->maxLength(80),
                $valor,
                Toggle::make('pergunta_opcao_ativa')
                    ->label('Ativa')
                    ->default(true)
                    ->inline(false),
            ])
            ->columns(3)
            ->minItems(1)
            ->defaultItems(1)
            ->addActionLabel('Adicionar opção')
            ->itemLabel(fn (array $state): ?string => $state['pergunta_opcao_nome'] ?? null)
            ->columnSpanFull();
    }
}
