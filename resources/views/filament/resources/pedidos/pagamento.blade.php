{{-- Pagamentos combinados (informativos) e venda vinculada do pedido. --}}
@php
    $pedido = $getRecord();
    $venda = $pedido->pedido_venda_id ? $pedido->vendas : null;
@endphp

<div class="space-y-3 text-sm">
    <div class="text-gray-500 dark:text-gray-400">
        @forelse ($pedido->pagamentosCombinados as $pagamento)
            <p>
                Combinado: {{ $pagamento->pg_pedido_opcaopagamento_nome }}
                ({{ Number::currency((float) $pagamento->pg_pedido_valor, 'BRL', 'pt_BR') }})
                @if ($pagamento->pg_pedido_pago)
                    <span class="ml-1 inline-flex rounded bg-success-50 px-1.5 py-0.5 text-[11px] font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">pago</span>
                @endif
                @if ($pagamento->pg_pedido_valor_troco_para)
                    &middot; Troco para {{ Number::currency((float) $pagamento->pg_pedido_valor_troco_para, 'BRL', 'pt_BR') }}
                @endif
            </p>
        @empty
            <p>Combinado: {{ $pedido->pedido_descricao_pagamento ?: '—' }}</p>
        @endforelse
    </div>

    @if ($venda)
        <p class="text-gray-950 dark:text-white">
            Venda #{{ $venda->id }} &middot; {{ $venda->venda_status }}
            &middot; pago {{ Number::currency((float) $venda->venda_valor_pago, 'BRL', 'pt_BR') }}
            de {{ Number::currency((float) $venda->venda_valor_total, 'BRL', 'pt_BR') }}
            @can('view:venda')
                &middot;
                <a href="{{ route('venda.imprimir', ['id' => $venda->id]) }}" target="_blank" class="text-primary-600 underline dark:text-primary-400">comprovante</a>
            @endcan
        </p>
    @endif
</div>
