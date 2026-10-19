<?php

namespace App\Filament\Resources\Pedidos\Schemas;

use App\Enums\StatusPedidoEnum;
use App\Models\Pedido;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

/**
 * Página "Ver pedido" (somente leitura). O ViewPedido carrega o pedido com
 * tudo que aparece aqui — nenhuma entrada faz query por conta própria.
 */
class PedidoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pedido excluído')
                ->icon('heroicon-o-trash')
                ->iconColor('danger')
                ->columns(4)
                ->columnSpanFull()
                ->visible(fn (Pedido $record): bool => $record->trashed())
                ->schema([
                    TextEntry::make('pedido_motivo_exclusao')->label('Motivo')->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                    TextEntry::make('usuarioExcluiu.name_first')->label('Excluído por')->placeholder('—'),
                    TextEntry::make('deleted_at')->label('Excluído em')->dateTime('d/m/Y H:i'),
                    TextEntry::make('pedido_exclusao_observacao')->label('Observação')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Pedido')
                ->columns(4)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('id')->label('Nº')->prefix('#'),
                    TextEntry::make('pedido_status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => StatusPedidoEnum::tryFrom($state)?->label() ?? $state)
                        ->icon(fn (string $state) => StatusPedidoEnum::tryFrom($state)?->icone())
                        ->color(fn (string $state): string => StatusPedidoEnum::tryFrom($state)?->cor() ?? 'gray'),
                    TextEntry::make('tipo_atendimento')
                        ->label('Atendimento')
                        ->state(fn (Pedido $record): string => $record->tipoAtendimento()->label()),
                    TextEntry::make('pedido_origem')
                        ->label('Origem')
                        ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                    TextEntry::make('pedido_datahora_abertura')->label('Abertura')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('mesa')
                        ->label('Mesa')
                        ->state(fn (Pedido $record): ?string => $record->pedido_sessao_mesa_id ? $record->sessaoMesa?->mesa?->mesa_nome : null)
                        ->visible(fn (Pedido $record): bool => $record->pedido_sessao_mesa_id !== null),
                    TextEntry::make('atendente')
                        ->label('Atendente')
                        ->state(fn (Pedido $record): ?string => $record->pedido_usuario_garcom_id ? $record->garcom?->name_first : null)
                        ->placeholder('—'),
                    TextEntry::make('entregador_nome')
                        ->label('Entregador')
                        ->state(fn (Pedido $record): ?string => $record->pedido_usuario_entrega_id ? $record->entregador?->name_first : null)
                        ->placeholder('—'),
                ]),

            Section::make('Cliente')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('cliente_nome')
                        ->label('Nome')
                        ->state(fn (Pedido $record): ?string => $record->pedido_cliente_id ? $record->cliente?->cliente_nome : null)
                        ->placeholder('Não informado'),
                    TextEntry::make('cliente_celular')
                        ->label('Celular')
                        ->state(fn (Pedido $record): ?string => $record->pedido_cliente_id ? $record->cliente?->cliente_celular : null)
                        ->placeholder('—'),
                    TextEntry::make('pedido_endereco_entrega')->label('Endereço de entrega')->placeholder('—'),
                ]),

            Section::make('Itens')
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('itens_inseridos')
                        ->hiddenLabel()
                        ->view('filament.resources.pedidos.itens')
                        ->viewData(['status' => 'INSERIDO']),
                ]),

            Section::make('Itens removidos')
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    ViewEntry::make('itens_removidos')
                        ->hiddenLabel()
                        ->view('filament.resources.pedidos.itens')
                        ->viewData(['status' => 'REMOVIDO']),
                ]),

            Section::make('Valores')
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('pedido_valor_itens')->label('Itens')->money('BRL'),
                    TextEntry::make('pedido_valor_desconto')->label('Desconto')->money('BRL'),
                    TextEntry::make('cupom_resumo')
                        ->label('Cupom')
                        ->visible(fn (Pedido $record): bool => $record->getAttribute('pedido_cupom_id') !== null)
                        ->state(fn (Pedido $record): string => ($record->cupom?->cupom_codigo ?? '#'.$record->pedido_cupom_id)
                            .' (− '.Number::currency((float) $record->pedido_valor_desconto_cupom, 'BRL', 'pt_BR').')'),
                    TextEntry::make('pedido_valor_frete')->label('Entrega')->money('BRL'),
                    TextEntry::make('pedido_valor_total')->label('Valor do pedido')->money('BRL')->weight('bold'),
                ]),

            Section::make('Pagamento')
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('situacao_pagamento')
                        ->label('Situação')
                        ->badge()
                        ->state(fn (Pedido $record): string => self::situacaoPagamento($record))
                        ->color(fn (string $state): string => match ($state) {
                            'Pago' => 'success',
                            'Parcial' => 'warning',
                            'A receber' => 'info',
                            default => 'gray',
                        }),
                    ViewEntry::make('pagamento')
                        ->hiddenLabel()
                        ->view('filament.resources.pedidos.pagamento'),
                ]),

            Section::make('Cancelamento')
                ->columns(4)
                ->columnSpanFull()
                ->visible(fn (Pedido $record): bool => $record->status() === StatusPedidoEnum::CANCELADO)
                ->schema([
                    TextEntry::make('pedido_motivo_cancelamento')->label('Motivo')->formatStateUsing(fn ($state): string => $state?->label() ?? 'Não informado'),
                    TextEntry::make('usuarioCancelou.name_first')->label('Cancelado por')->placeholder('Cliente / não identificado'),
                    TextEntry::make('pedido_datahora_cancelado')->label('Cancelado em')->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('pedido_motivo_cancelamento_observacao')->label('Observação')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Linha do tempo')
                ->columnSpanFull()
                ->schema([
                    ViewEntry::make('linha_do_tempo')
                        ->hiddenLabel()
                        ->view('filament.resources.pedidos.linha-do-tempo'),
                ]),
        ]);
    }

    /**
     * Pago / A receber / Parcial, a partir da venda vinculada e dos itens
     * lançados em venda (a mesa pode pagar por item). Pedido cancelado não
     * tem situação de pagamento. Usa os itens já carregados pelo ViewPedido.
     */
    public static function situacaoPagamento(Pedido $pedido): string
    {
        if ($pedido->status() === StatusPedidoEnum::CANCELADO) {
            return '—';
        }

        if ($pedido->pedido_venda_id !== null) {
            return 'Pago';
        }

        $itens = $pedido->item_pedido_pedido_id->where('item_pedido_status', 'INSERIDO');
        $vendidos = $itens->whereNotNull('item_pedido_venda_id')->count();

        return match (true) {
            $vendidos === 0 => 'A receber',
            $vendidos === $itens->count() => 'Pago',
            default => 'Parcial',
        };
    }
}
