<?php

namespace App\Filament\Resources\Pedidos\Schemas;

use App\Filament\Forms\Components\PedidoItensField;
use App\Filament\Tables\Clientes;
use App\Models\OpcoesEntregas;
use App\Models\Venda;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ModalTableSelect;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

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
                            PedidoItensField::make('itens_pedido_selecionados')
                                ->label('Produtos')
                                ->columnSpanFull(),
                        ]),

                    Step::make('Entrega e Pagamento')
                        ->description('Tipo de entrega e observações')
                        ->icon('heroicon-o-truck')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    Select::make('pedido_opcaoentrega_id')
                                        ->label('Opção de entrega')
                                        ->options(fn () => OpcoesEntregas::query()
                                            ->orderBy('opcaoentrega_nome')
                                            ->pluck('opcaoentrega_nome', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->native(false),

                                    Select::make('pedido_venda_id')
                                        ->label('Venda vinculada')
                                        ->options(fn () => Venda::query()
                                            ->orderByDesc('id')
                                            ->limit(500)
                                            ->get()
                                            ->mapWithKeys(fn (Venda $venda) => [
                                                $venda->id => '#'.$venda->id.' - R$ '.number_format((float) ($venda->venda_valor_total ?? 0), 2, ',', '.'),
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
                                ->content(fn ($get): string => 'Total atual: R$ '.number_format((float) ($get('pedido_valor_total') ?? 0), 2, ',', '.')),
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
}
