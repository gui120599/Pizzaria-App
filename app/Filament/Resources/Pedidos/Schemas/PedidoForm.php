<?php

namespace App\Filament\Resources\Pedidos\Schemas;

use App\Filament\Tables\Clientes;
use App\Models\Cliente;
use App\Models\OpcoesEntregas;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Models\Venda;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Leandrocfe\FilamentPtbrFormFields\Money;

class PedidoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Atendimento')
                        ->description('Cliente, mesa e equipe')
                        ->icon('heroicon-o-user-group')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    ModalTableSelect::make('pedido_cliente_id')
                                        ->relationship('cliente', 'cliente_nome')
                                        ->tableConfiguration(Clientes::class),

                                    Hidden::make('pedido_garcom_id')
                                        ->default(Auth::id()),
                                ]),
                        ]),

                    Step::make('Itens do Pedido')
                        ->description('Selecione os produtos e quantidades')
                        ->icon('heroicon-o-shopping-bag')
                        ->schema([
                            Repeater::make('item_pedido_pedido_id')
                                ->label('Itens')
                                ->relationship('item_pedido_pedido_id')
                                ->defaultItems(2)
                                ->addActionLabel('Adicionar item')
                                ->collapsible()
                                ->reorderable(false)
                                ->itemLabel(function (array $state): ?string {
                                    $quantidade = (float) ($state['item_pedido_quantidade'] ?? 0);
                                    $produtoId = $state['item_pedido_produto_id'] ?? null;

                                    if (! $produtoId) {
                                        return 'Novo item';
                                    }

                                    $produtoNome = Produto::query()
                                        ->whereKey($produtoId)
                                        ->value('produto_descricao');

                                    if (! $produtoNome) {
                                        return 'Item #' . $produtoId;
                                    }

                                    return $produtoNome . ' x' . number_format($quantidade, 2, ',', '.');
                                })
                                ->afterStateUpdated(function (?array $state, $set, $get): void {
                                    self::atualizarTotaisPedidoPorItens($state, $get, $set);
                                })
                                ->afterStateHydrated(function (?array $state, $set, $get): void {
                                    self::atualizarTotaisPedidoPorItens($state, $get, $set);
                                })
                                ->schema([
                                    Select::make('item_pedido_produto_id')
                                        ->label('Produto')
                                        ->options(fn() => Produto::query()
                                            ->orderBy('produto_descricao')
                                            ->pluck('produto_descricao', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->required()
                                        ->columnSpan(4)
                                        ->live()
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            $valorPadrao = Produto::query()
                                                ->whereKey($state)
                                                ->value('produto_preco_venda');

                                            $set('item_pedido_valor_unitario', round((float) ($valorPadrao ?? 0), 2));
                                            self::atualizarValorItem($get, $set);
                                        }),

                                    TextInput::make('item_pedido_quantidade')
                                        ->label('Qtd.')
                                        ->default(1)
                                        ->live()
                                        ->reactive()
                                        ->columnSpan(2)
                                        ->prefixActions([

                                            Action::make('terco')
                                                ->label('⅓')
                                                ->tooltip('UM TERÇO')
                                                ->button()
                                                ->action(function ($get, $set) {
                                                    $set('item_pedido_quantidade', 0.33);
                                                    self::atualizarValorItem($get, $set);
                                                }),

                                            Action::make('metade')
                                                ->label('½')
                                                ->tooltip('METADE')
                                                ->button()
                                                ->action(function ($get, $set) {
                                                    $set('item_pedido_quantidade', 0.5);
                                                    self::atualizarValorItem($get, $set);
                                                }),

                                            Action::make('inteiro')
                                                ->label('1x')
                                                ->button()
                                                ->action(function ($get, $set) {
                                                    $set('item_pedido_quantidade', 1);
                                                    self::atualizarValorItem($get, $set);
                                                }),


                                        ])
                                        ->suffixActions([

                                            Action::make('decrement')
                                                ->icon('heroicon-m-minus')
                                                ->tooltip('DIMINUIR')
                                                ->action(function ($get, $set, $state) {
                                                    $atual = (float) ($state ?? 1);
                                                    $novaQtd = max(1, round($atual - 1, 2));
                                                    $set('item_pedido_quantidade', $novaQtd);
                                                    self::atualizarValorItem($get, $set);
                                                }),

                                            Action::make('increment')
                                                ->icon('heroicon-m-plus')
                                                ->tooltip('AUMENTAR')
                                                ->action(function ($get, $set, $state) {
                                                    $novaQtd = (float) ($state ?? 1) + 1;
                                                    $set('item_pedido_quantidade', $novaQtd);
                                                    self::atualizarValorItem($get, $set);
                                                }),
                                        ])
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            self::atualizarValorItem($get, $set);
                                        })
                                        ->extraInputAttributes([
                                            'class' => '!text-center',
                                            'style' => 'text-align: center !important;',
                                        ]),

                                    Money::make('item_pedido_valor_unitario')
                                        ->label('Valor unitário')
                                        ->required()
                                        ->columnSpan(2)
                                        ->live(onBlur: true) // só atualiza ao perder foco, evita o problema
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            self::atualizarValorItem($get, $set);
                                        }),

                                    Money::make('item_pedido_desconto')
                                        ->label('Desconto')
                                        ->columnSpan(2)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            self::atualizarValorItem($get, $set);
                                        }),

                                    Money::make('item_pedido_valor_adicionais')
                                        ->label('Adicionais')
                                        ->columnSpan(2)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            self::atualizarValorItem($get, $set);
                                        }),

                                    Money::make('item_pedido_valor')
                                        ->label('Valor do item')
                                        ->default(0.00)
                                        ->required()
                                        ->columnSpan(2),

                                    TextInput::make('item_pedido_status')
                                        ->default('INSERIDO'),

                                    Textarea::make('item_pedido_observacao')
                                        ->label('Observação do item')
                                        ->rows(2)
                                        ->columnSpan(9),
                                ]),
                        ]),

                    Step::make('Entrega e Pagamento')
                        ->description('Tipo de entrega e observações')
                        ->icon('heroicon-o-truck')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Select::make('pedido_opcaoentrega_id')
                                        ->label('Opção de entrega')
                                        ->options(fn() => OpcoesEntregas::query()
                                            ->orderBy('opcaoentrega_nome')
                                            ->pluck('opcaoentrega_nome', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->native(false),

                                    Select::make('pedido_venda_id')
                                        ->label('Venda vinculada')
                                        ->options(fn() => Venda::query()
                                            ->orderByDesc('id')
                                            ->limit(500)
                                            ->get()
                                            ->mapWithKeys(fn(Venda $venda) => [
                                                $venda->id => '#' . $venda->id . ' - R$ ' . number_format((float) ($venda->venda_valor_total ?? 0), 2, ',', '.'),
                                            ])
                                            ->toArray())
                                        ->searchable()
                                        ->native(false),
                                ]),

                            TextInput::make('pedido_descricao_pagamento')
                                ->label('Descrição do pagamento')
                                ->placeholder('Ex: Pix, cartão, dinheiro'),

                            Textarea::make('pedido_observacao_pagamento')
                                ->label('Observação do pagamento')
                                ->rows(3)
                                ->columnSpanFull(),

                            Textarea::make('pedido_endereco_entrega')
                                ->label('Endereço de entrega')
                                ->rows(3)
                                ->columnSpanFull(),
                        ]),


                    Step::make('Valores')
                        ->description('Totais do pedido')
                        ->icon('heroicon-o-currency-dollar')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    TextInput::make('pedido_valor_itens')
                                        ->label('Valor dos itens')
                                        ->numeric()
                                        ->step(0.01)
                                        ->default(0.00)
                                        ->readOnly()
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            $itens = (float) ($state ?? 0);
                                            $desconto = (float) ($get('pedido_valor_desconto') ?? 0);
                                            $set('pedido_valor_total', round(max(0, $itens - $desconto), 2));
                                        }),

                                    TextInput::make('pedido_valor_desconto')
                                        ->label('Desconto')
                                        ->numeric()
                                        ->step(0.01)
                                        ->default(0.00)
                                        ->live()
                                        ->afterStateUpdated(function ($state, $set, $get): void {
                                            $itens = (float) ($get('pedido_valor_itens') ?? 0);
                                            $desconto = (float) ($state ?? 0);
                                            $set('pedido_valor_total', round(max(0, $itens - $desconto), 2));
                                        }),

                                    TextInput::make('pedido_valor_total')
                                        ->label('Total')
                                        ->numeric()
                                        ->step(0.01)
                                        ->default(0.00)
                                        ->readOnly()
                                        ->required(),
                                ]),

                            Placeholder::make('resumo_total')
                                ->label('Resumo')
                                ->content(fn($get): string => 'Total atual: R$ ' . number_format((float) ($get('pedido_valor_total') ?? 0), 2, ',', '.')),
                        ]),

                    Step::make('Status e Cronologia')
                        ->description('Status atual e marcos do pedido')
                        ->icon('heroicon-o-clock')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Select::make('pedido_status')
                                        ->label('Status')
                                        ->options([
                                            'INICIADO' => 'Iniciado',
                                            'ABERTO' => 'Aberto',
                                            'PREPARANDO' => 'Preparando',
                                            'PRONTO' => 'Pronto',
                                            'EM TRANSPORTE' => 'Em transporte',
                                            'ENTREGUE' => 'Entregue',
                                            'FINALIZADO' => 'Finalizado',
                                            'CANCELADO' => 'Cancelado',
                                        ])
                                        ->default('INICIADO')
                                        ->required()
                                        ->native(false),

                                    DateTimePicker::make('pedido_datahora_incio')
                                        ->label('Início')
                                        ->default(now())
                                        ->required(),
                                ]),

                            Grid::make(2)
                                ->schema([
                                    DateTimePicker::make('pedido_datahora_abertura')
                                        ->label('Abertura'),
                                    DateTimePicker::make('pedido_datahora_preparo')
                                        ->label('Preparo'),
                                    DateTimePicker::make('pedido_datahora_pronto')
                                        ->label('Pronto'),
                                    DateTimePicker::make('pedido_datahora_transporte')
                                        ->label('Transporte'),
                                    DateTimePicker::make('pedido_datahora_entrega')
                                        ->label('Entrega'),
                                    DateTimePicker::make('pedido_datahora_finalizado')
                                        ->label('Finalizado'),
                                    DateTimePicker::make('pedido_datahora_cancelado')
                                        ->label('Cancelado'),
                                ]),
                        ]),
                ])
                    ->columnSpanFull()
                    ->startOnStep(1),
            ]);
    }

    protected static function atualizarValorItem(Get $get, Set $set): void
    {
        $quantidade = (float) ($get('item_pedido_quantidade') ?? 0);
        $valorUnitario = (float) ($get('item_pedido_valor_unitario') ?? 0);
        $desconto = (float) ($get('item_pedido_desconto') ?? 0);
        $adicionais = (float) ($get('item_pedido_valor_adicionais') ?? 0);

        $valorItem = max(0, (($quantidade * $valorUnitario) + $adicionais) - $desconto);

        $set('item_pedido_valor', round($valorItem, 2));
    }

    protected static function atualizarTotaisPedidoPorItens(?array $itens, Get $get, Set $set): void
    {
        $valorItens = 0.0;

        foreach (($itens ?? []) as $item) {
            $valorItens += (float) ($item['item_pedido_valor'] ?? 0);
        }

        $valorItens = round($valorItens, 2);
        $descontoPedido = (float) ($get('pedido_valor_desconto') ?? 0);
        $valorTotal = round(max(0, $valorItens - $descontoPedido), 2);

        $set('pedido_valor_itens', $valorItens);
        $set('pedido_valor_total', $valorTotal);
    }
}
