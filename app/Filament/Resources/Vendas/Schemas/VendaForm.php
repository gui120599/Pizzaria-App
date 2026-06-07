<?php

namespace App\Filament\Resources\Vendas\Schemas;

use App\Filament\Tables\Clientes;
use App\Models\OpcoesPagamento;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Leandrocfe\FilamentPtbrFormFields\Money;

class VendaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([

                    Step::make('Dados da Venda')
                        ->description('Sessão, cliente e status')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    Select::make('venda_sessao_caixa_id')
                                        ->label('Sessão do Caixa')
                                        ->options(fn() => SessaoCaixa::query()
                                            ->orderByDesc('id')
                                            ->limit(100)
                                            ->get()
                                            ->mapWithKeys(fn(SessaoCaixa $s) => [
                                                $s->id => '#' . $s->id . ' — ' . ($s->caixa->caixa_nome ?? ''),
                                            ])
                                            ->toArray())
                                        ->searchable()
                                        ->native(false)
                                        ->required(),

                                    ModalTableSelect::make('venda_cliente_id')
                                        ->relationship('cliente', 'cliente_nome')
                                        ->tableConfiguration(Clientes::class),

                                    Select::make('venda_status')
                                        ->label('Status')
                                        ->options([
                                            'INICIADA'   => 'Iniciada',
                                            'FINALIZADA' => 'Finalizada',
                                            'CANCELADA'  => 'Cancelada',
                                        ])
                                        ->default('INICIADA')
                                        ->required()
                                        ->native(false),
                                ]),

                            Grid::make(2)
                                ->schema([
                                    DateTimePicker::make('venda_datahora_iniciada')
                                        ->label('Iniciada em')
                                        ->default(now())
                                        ->readOnly(),

                                    DateTimePicker::make('venda_datahora_finalizada')
                                        ->label('Finalizada em')
                                        ->readOnly(),
                                ]),
                        ]),

                    Step::make('Itens')
                        ->description('Produtos da venda')
                        ->icon('heroicon-o-shopping-bag')
                        ->schema([
                            Repeater::make('itensVenda')
                                ->label('Itens')
                                ->relationship('itensVenda')
                                ->addActionLabel('Adicionar item')
                                ->collapsible()
                                ->reorderable(false)
                                ->itemLabel(function (array $state): ?string {
                                    $produtoId = $state['item_venda_produto_id'] ?? null;
                                    if (! $produtoId) {
                                        return 'Novo item';
                                    }
                                    $nome = Produto::query()->whereKey($produtoId)->value('produto_descricao');
                                    $qtd  = number_format((float) ($state['item_venda_quantidade'] ?? 0), 2, ',', '.');
                                    return ($nome ?? 'Produto') . ' x' . $qtd;
                                })
                                ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                                    self::recalcularTotaisDeItens($state, $set, $get);
                                })
                                ->afterStateHydrated(function (?array $state, Set $set, Get $get): void {
                                    self::recalcularTotaisDeItens($state, $set, $get);
                                })
                                ->columns(12)
                                ->schema([
                                    Select::make('item_venda_produto_id')
                                        ->label('Produto')
                                        ->options(fn() => Produto::query()
                                            ->orderBy('produto_descricao')
                                            ->pluck('produto_descricao', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->columnSpan(5)
                                        ->live()
                                        ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                            $produto = Produto::find($state);
                                            if ($produto) {
                                                $promo   = (float) ($produto->produto_preco_promocional ?? 0);
                                                $venda   = (float) $produto->produto_preco_venda;
                                                $efetivo = $promo > 0 ? $promo : $venda;
                                                $set('item_venda_valor_unitario', round($efetivo, 2));
                                            }
                                            self::recalcularValorItem($get, $set);
                                        }),

                                    TextInput::make('item_venda_quantidade')
                                        ->label('Qtd.')
                                        ->default(1)
                                        ->numeric()
                                        ->live()
                                        ->columnSpan(2)
                                        ->prefixActions([
                                            Action::make('metade')
                                                ->label('½')
                                                ->tooltip('Meia')
                                                ->button()
                                                ->action(function (Set $set, Get $get) {
                                                    $set('item_venda_quantidade', 0.5);
                                                    self::recalcularValorItem($get, $set);
                                                }),
                                            Action::make('inteiro')
                                                ->label('1x')
                                                ->button()
                                                ->action(function (Set $set, Get $get) {
                                                    $set('item_venda_quantidade', 1);
                                                    self::recalcularValorItem($get, $set);
                                                }),
                                        ])
                                        ->suffixActions([
                                            Action::make('decrement')
                                                ->icon('heroicon-m-minus')
                                                ->tooltip('Diminuir')
                                                ->action(function ($state, Set $set, Get $get) {
                                                    $set('item_venda_quantidade', max(0.5, (float) ($state ?? 1) - 1));
                                                    self::recalcularValorItem($get, $set);
                                                }),
                                            Action::make('increment')
                                                ->icon('heroicon-m-plus')
                                                ->tooltip('Aumentar')
                                                ->action(function ($state, Set $set, Get $get) {
                                                    $set('item_venda_quantidade', (float) ($state ?? 1) + 1);
                                                    self::recalcularValorItem($get, $set);
                                                }),
                                        ])
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            self::recalcularValorItem($get, $set);
                                        })
                                        ->extraInputAttributes(['style' => 'text-align:center']),

                                    Money::make('item_venda_valor_unitario')
                                        ->label('Vlr. Unit.')
                                        ->columnSpan(2)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            self::recalcularValorItem($get, $set);
                                        }),

                                    Money::make('item_venda_desconto')
                                        ->label('Desconto')
                                        ->default(0)
                                        ->columnSpan(2)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            self::recalcularValorItem($get, $set);
                                        }),

                                    Money::make('item_venda_valor')
                                        ->label('Total')
                                        ->default(0)
                                        ->readOnly()
                                        ->columnSpan(2),

                                    Hidden::make('item_venda_status')
                                        ->default('INSERIDO'),
                                ]),
                        ]),

                    Step::make('Pagamentos')
                        ->description('Formas de pagamento')
                        ->icon('heroicon-o-credit-card')
                        ->schema([
                            Repeater::make('pagamentos')
                                ->label('Pagamentos')
                                ->relationship('pagamentos')
                                ->addActionLabel('Adicionar pagamento')
                                ->collapsible()
                                ->reorderable(false)
                                ->itemLabel(function (array $state): ?string {
                                    $opcaoId = $state['pg_venda_opcaopagamento_id'] ?? null;
                                    $valor   = number_format((float) ($state['pg_venda_valor_pagamento'] ?? 0), 2, ',', '.');
                                    if (! $opcaoId) {
                                        return 'Novo pagamento';
                                    }
                                    $nome = OpcoesPagamento::query()->whereKey($opcaoId)->value('opcaopag_nome');
                                    return ($nome ?? 'Pagamento') . ' — R$ ' . $valor;
                                })
                                ->afterStateUpdated(function (?array $state, Set $set): void {
                                    self::recalcularTotaisDePagamentos($state, $set);
                                })
                                ->afterStateHydrated(function (?array $state, Set $set): void {
                                    self::recalcularTotaisDePagamentos($state, $set);
                                })
                                ->columns(6)
                                ->schema([
                                    Select::make('pg_venda_opcaopagamento_id')
                                        ->label('Forma de pagamento')
                                        ->options(fn() => OpcoesPagamento::query()
                                            ->orderBy('opcaopag_nome')
                                            ->pluck('opcaopag_nome', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->native(false)
                                        ->required()
                                        ->columnSpan(3),

                                    Money::make('pg_venda_valor_pagamento')
                                        ->label('Valor pago')
                                        ->required()
                                        ->columnSpan(2)
                                        ->live(onBlur: true),

                                    Money::make('pg_venda_valor_acrescimo')
                                        ->label('Acréscimo')
                                        ->default(0)
                                        ->columnSpan(1),

                                    Money::make('pg_venda_valor_desconto')
                                        ->label('Desconto')
                                        ->default(0)
                                        ->columnSpan(1),
                                ]),
                        ]),

                    Step::make('Valores')
                        ->description('Totais da venda')
                        ->icon('heroicon-o-currency-dollar')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    Money::make('venda_valor_frete')
                                        ->label('Frete')
                                        ->default(0)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                            self::recalcularTotal($get, $set);
                                        }),

                                    Money::make('venda_valor_itens')
                                        ->label('Valor dos itens')
                                        ->readOnly(),

                                    Money::make('venda_valor_acrescimo')
                                        ->label('Acréscimo')
                                        ->readOnly(),

                                    Money::make('venda_valor_desconto')
                                        ->label('Desconto')
                                        ->readOnly(),

                                    Money::make('venda_valor_total')
                                        ->label('Total')
                                        ->readOnly(),

                                    Money::make('venda_valor_pago')
                                        ->label('Valor pago')
                                        ->readOnly(),

                                    Money::make('venda_valor_troco')
                                        ->label('Troco')
                                        ->readOnly(),
                                ]),

                            Placeholder::make('resumo_venda')
                                ->label('Resumo')
                                ->content(fn(Get $get): string => implode(' | ', [
                                    'Itens: R$ ' . number_format((float) ($get('venda_valor_itens') ?? 0), 2, ',', '.'),
                                    'Total: R$ ' . number_format((float) ($get('venda_valor_total') ?? 0), 2, ',', '.'),
                                    'Pago: R$ '  . number_format((float) ($get('venda_valor_pago') ?? 0), 2, ',', '.'),
                                    'Troco: R$ ' . number_format((float) ($get('venda_valor_troco') ?? 0), 2, ',', '.'),
                                ])),
                        ]),

                ])
                    ->columnSpanFull()
                    ->startOnStep(1),
            ]);
    }

    protected static function recalcularValorItem(Get $get, Set $set): void
    {
        $quantidade = (float) ($get('item_venda_quantidade') ?? 0);
        $unitario   = (float) ($get('item_venda_valor_unitario') ?? 0);
        $desconto   = (float) ($get('item_venda_desconto') ?? 0);
        $adicionais = (float) ($get('item_venda_valor_adicionais') ?? 0);

        $valor = max(0, ($quantidade * $unitario) + $adicionais - $desconto);
        $set('item_venda_valor', round($valor, 2));
    }

    protected static function recalcularTotaisDeItens(?array $itens, Set $set, Get $get): void
    {
        $totalItens = collect($itens ?? [])->sum(fn($item) => (float) ($item['item_venda_valor'] ?? 0));
        $set('venda_valor_itens', round($totalItens, 2));
        self::recalcularTotal($get, $set);
    }

    protected static function recalcularTotaisDePagamentos(?array $pagamentos, Set $set): void
    {
        $totalPago     = collect($pagamentos ?? [])->sum(fn($pg) => (float) ($pg['pg_venda_valor_pagamento'] ?? 0));
        $totalAcrescimo = collect($pagamentos ?? [])->sum(fn($pg) => (float) ($pg['pg_venda_valor_acrescimo'] ?? 0));
        $totalDesconto  = collect($pagamentos ?? [])->sum(fn($pg) => (float) ($pg['pg_venda_valor_desconto'] ?? 0));

        $set('venda_valor_pago', round($totalPago, 2));
        $set('venda_valor_acrescimo', round($totalAcrescimo, 2));
        $set('venda_valor_desconto', round($totalDesconto, 2));
    }

    protected static function recalcularTotal(Get $get, Set $set): void
    {
        $itens      = (float) ($get('venda_valor_itens') ?? 0);
        $frete      = (float) ($get('venda_valor_frete') ?? 0);
        $acrescimo  = (float) ($get('venda_valor_acrescimo') ?? 0);
        $desconto   = (float) ($get('venda_valor_desconto') ?? 0);
        $pago       = (float) ($get('venda_valor_pago') ?? 0);

        $total = round(max(0, $itens + $frete + $acrescimo - $desconto), 2);
        $troco = round(max(0, $pago - $total), 2);

        $set('venda_valor_total', $total);
        $set('venda_valor_troco', $troco);
    }
}
