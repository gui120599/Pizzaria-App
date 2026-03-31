<?php

namespace App\Filament\Resources\Pedidos\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PedidoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('pedido_cliente_id')
                    ->numeric(),
                TextInput::make('pedido_sessao_mesa_id')
                    ->numeric(),
                TextInput::make('pedido_usuario_garcom_id')
                    ->numeric(),
                TextInput::make('pedido_usuario_entrega_id')
                    ->numeric(),
                TextInput::make('pedido_opcaoentrega_id')
                    ->numeric(),
                TextInput::make('pedido_venda_id')
                    ->numeric(),
                TextInput::make('pedido_descricao_pagamento'),
                Textarea::make('pedido_observacao_pagamento')
                    ->columnSpanFull(),
                Textarea::make('pedido_endereco_entrega')
                    ->columnSpanFull(),
                TextInput::make('pedido_valor_itens')
                    ->numeric()
                    ->default(0.0),
                TextInput::make('pedido_valor_desconto')
                    ->numeric()
                    ->default(0.0),
                TextInput::make('pedido_valor_total')
                    ->numeric()
                    ->default(0.0),
                Select::make('pedido_status')
                    ->options([
            'INICIADO' => 'I n i c i a d o',
            'ABERTO' => 'A b e r t o',
            'PREPARANDO' => 'P r e p a r a n d o',
            'PRONTO' => 'P r o n t o',
            'EM TRANSPORTE' => 'E m t r a n s p o r t e',
            'ENTREGUE' => 'E n t r e g u e',
            'FINALIZADO' => 'F i n a l i z a d o',
            'CANCELADO' => 'C a n c e l a d o',
        ])
                    ->default('INICIADO')
                    ->required(),
                DateTimePicker::make('pedido_datahora_incio')
                    ->required(),
                DateTimePicker::make('pedido_datahora_abertura'),
                DateTimePicker::make('pedido_datahora_preparo'),
                DateTimePicker::make('pedido_datahora_pronto'),
                DateTimePicker::make('pedido_datahora_transporte'),
                DateTimePicker::make('pedido_datahora_entrega'),
                DateTimePicker::make('pedido_datahora_finalizado'),
                DateTimePicker::make('pedido_datahora_cancelado'),
            ]);
    }
}
