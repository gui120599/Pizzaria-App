{{--
    Modal "Ver detalhes" do Painel de Pedidos.

    O pedido chega aqui já carregado com tudo que precisa (ver
    PedidoStatusActions::verDetalhes()) — nada de query extra na view.
--}}
@php
    use App\Support\FormatoQuantidade;
    use App\Support\TotaisPedido;

    $status = $pedido->status();
    $itens = $pedido->item_pedido_pedido_id;
    $totais = TotaisPedido::paraItens($itens, $pedido->opcaoEntrega, (float) ($pedido->pedido_valor_desconto ?? 0));
@endphp

<div class="space-y-5 text-sm">
    {{-- Cabeçalho --}}
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-3 dark:border-white/10">
        <div>
            <p class="text-lg font-bold text-gray-950 dark:text-white">Pedido #{{ $pedido->id }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $pedido->pedido_datahora_abertura?->format('d/m/Y H:i') }}
                &middot; {{ $pedido->tipoAtendimento()->label() }}
            </p>
        </div>

        @if ($status)
            <span
                @class([
                    'rounded-full px-3 py-1 text-xs font-bold uppercase',
                    'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300' => $status->cor() === 'gray',
                    'bg-info-100 text-info-700 dark:bg-info-500/10 dark:text-info-400' => $status->cor() === 'info',
                    'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $status->cor() === 'warning',
                    'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $status->cor() === 'success',
                    'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => $status->cor() === 'danger',
                ])
            >
                {{ $status->label() }}
            </span>
        @endif
    </div>

    {{-- Cliente --}}
    <div>
        <p class="mb-1 text-xs font-bold uppercase text-gray-400">Cliente</p>
        <p class="text-gray-950 dark:text-white">{{ $pedido->cliente->cliente_nome }}</p>
        @if ($pedido->cliente->cliente_celular)
            <p class="text-gray-500 dark:text-gray-400">{{ $pedido->cliente->cliente_celular }}</p>
        @endif
    </div>

    {{-- Entrega --}}
    @if ($pedido->pedido_sessao_mesa_id)
        <div>
            <p class="mb-1 text-xs font-bold uppercase text-gray-400">Mesa</p>
            <p class="text-gray-950 dark:text-white">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</p>
        </div>
    @elseif ($pedido->exigeEntrega())
        <div>
            <p class="mb-1 text-xs font-bold uppercase text-gray-400">Entrega</p>
            <p class="text-gray-950 dark:text-white">{{ $pedido->opcaoEntrega->opcaoentrega_nome }}</p>
            <p class="text-gray-500 dark:text-gray-400">{{ $pedido->pedido_endereco_entrega ?? '—' }}</p>
            @if ($pedido->pedido_usuario_entrega_id)
                <p class="mt-1 text-gray-500 dark:text-gray-400">Entregador: {{ $pedido->entregador->name_first }}</p>
            @endif
        </div>
    @endif

    {{-- Itens --}}
    <div>
        <p class="mb-1 text-xs font-bold uppercase text-gray-400">Itens</p>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($itens as $item)
                <div class="flex items-start justify-between gap-3 py-1.5">
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5">
                            <div class="text-gray-950 dark:text-white">
                                {{ FormatoQuantidade::item($item->item_pedido_quantidade) }}x
                                <x-item-nome :item="$item" />
                            </div>

                            @if ($item->item_pedido_origem_id)
                                <span class="shrink-0 text-xs font-semibold text-success-600 dark:text-success-400" title="Oferta de promoção adicional">
                                    🎁
                                </span>
                            @endif
                        </div>

                        @foreach ($item->adicionaisItemPedido as $adicional)
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                + {{ (int) $adicional->aip_quantidade }} {{ $adicional->adicional?->adicional_nome }}
                            </p>
                        @endforeach

                        @if ($item->item_pedido_observacao)
                            <p class="text-xs italic text-amber-700 dark:text-amber-400">Obs: {{ $item->item_pedido_observacao }}</p>
                        @endif
                    </div>

                    <span class="shrink-0 text-gray-700 dark:text-gray-300">
                        {{ Number::currency((float) $item->item_pedido_valor, 'BRL', 'pt_BR') }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Financeiro --}}
    <div>
        <p class="mb-1 text-xs font-bold uppercase text-gray-400">Valores</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
            <div>
                <p class="text-xs text-gray-400">Subtotal</p>
                <p class="text-gray-950 dark:text-white">{{ Number::currency($totais['itens'], 'BRL', 'pt_BR') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Desconto</p>
                <p class="text-gray-950 dark:text-white">{{ Number::currency($totais['desconto'], 'BRL', 'pt_BR') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Entrega</p>
                <p class="text-gray-950 dark:text-white">{{ Number::currency($totais['frete'], 'BRL', 'pt_BR') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Total</p>
                <p class="font-bold text-gray-950 dark:text-white">{{ Number::currency($totais['total'], 'BRL', 'pt_BR') }}</p>
            </div>
        </div>

        <div class="mt-2 text-gray-500 dark:text-gray-400">
            @forelse ($pedido->pagamentosCombinados as $pagamento)
                <p>
                    Pagamento: {{ $pagamento->pg_pedido_opcaopagamento_nome }}
                    @if ($pedido->pagamentosCombinados->count() > 1)
                        ({{ Number::currency((float) $pagamento->pg_pedido_valor, 'BRL', 'pt_BR') }})
                    @endif
                    @if ($pagamento->pg_pedido_valor_troco_para)
                        &middot; Troco para {{ Number::currency((float) $pagamento->pg_pedido_valor_troco_para, 'BRL', 'pt_BR') }}
                    @endif
                </p>
            @empty
                @if ($pedido->pedido_descricao_pagamento)
                    <p>
                        Pagamento: {{ $pedido->pedido_descricao_pagamento }}
                        @if ($pedido->pedido_observacao_pagamento)
                            &middot; {{ $pedido->pedido_observacao_pagamento }}
                        @endif
                    </p>
                @else
                    <p>Pagamento: —</p>
                @endif
            @endforelse
        </div>
    </div>

    {{-- Cancelamento --}}
    @if ($status === \App\Enums\StatusPedidoEnum::CANCELADO)
        <div class="rounded-lg bg-danger-50 p-3 dark:bg-danger-500/5">
            <p class="mb-1 text-xs font-bold uppercase text-danger-600 dark:text-danger-400">Cancelamento</p>
            <p class="text-gray-950 dark:text-white">{{ $pedido->pedido_motivo_cancelamento?->label() ?? '—' }}</p>
            <p class="text-gray-500 dark:text-gray-400">
                Por: {{ $pedido->usuarioCancelou?->name_first ?? 'Cliente / não identificado' }}
            </p>
        </div>
    @endif

    {{-- Linha do tempo --}}
    <div>
        <p class="mb-2 text-xs font-bold uppercase text-gray-400">Linha do tempo</p>
        @if ($pedido->historicoStatus->isEmpty())
            <p class="text-xs text-gray-400">Sem histórico de transições registrado.</p>
        @else
            <ol class="relative ml-2 border-l-2 border-gray-200 dark:border-white/10">
                @foreach ($pedido->historicoStatus as $entrada)
                    <li class="relative mb-4 ml-4 last:mb-0">
                        <span class="absolute -left-[21px] h-2.5 w-2.5 rounded-full border-2 border-white bg-primary-500 dark:border-gray-900"></span>
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ \App\Enums\StatusPedidoEnum::tryFrom($entrada->hsp_status_para)?->label() ?? $entrada->hsp_status_para }}
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ $entrada->created_at?->format('d/m H:i') }} — {{ $entrada->usuario->name_first }}
                        </p>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
