@php $itens = $pedido->item_pedido_pedido_id; @endphp

<div x-data="{ open: false }" class="border-t border-gray-100">
    <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50">
        <span class="flex items-center gap-1.5">
            <i class='bx bx-receipt'></i>
            {{ $itens->count() }} {{ $itens->count() === 1 ? 'item' : 'itens' }} do pedido
        </span>
        <i class='bx bx-chevron-down transition-transform' x-bind:class="open ? 'rotate-180' : ''"></i>
    </button>

    <div x-show="open" x-transition class="px-4 pb-3 space-y-2" style="display: none;">
        @foreach($itens as $item)
            @php
                $qtd = (float) $item->item_pedido_quantidade;
                $qtdFormatada = match (true) {
                    abs($qtd - 0.5) < 0.01 => '½',
                    abs($qtd - 1 / 3) < 0.01 => '⅓',
                    abs($qtd - 2 / 3) < 0.01 => '⅔',
                    $qtd == (int) $qtd => (string) (int) $qtd,
                    default => number_format($qtd, 2, ',', ''),
                };
            @endphp
            <div class="flex items-start justify-between gap-2 text-sm border-b border-gray-50 pb-2 last:border-0 last:pb-0">
                <div class="flex-1 min-w-0">
                    @if($item->produto?->categoria)
                        <span class="inline-block px-1.5 py-0.5 bg-orange-50 text-orange-600 border border-orange-200 rounded text-[10px] font-semibold leading-tight mb-0.5">
                            {{ $item->produto->categoria->categoria_nome }}
                        </span>
                    @endif
                    <p class="text-gray-800">
                        <span class="font-semibold text-green-600">{{ $qtdFormatada }}×</span>
                        {{ $item->produto?->produto_descricao ?? '—' }}
                    </p>
                    @if($item->item_pedido_observacao)
                        <p class="text-gray-400 text-xs">{{ $item->item_pedido_observacao }}</p>
                    @endif
                </div>
                <span class="text-gray-500 text-xs shrink-0">R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}</span>
            </div>
        @endforeach
    </div>
</div>
