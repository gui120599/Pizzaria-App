{{--
    Itens do pedido na página "Ver pedido" (somente leitura). O pedido chega
    com todos os itens carregados (ver ViewPedido::resolveRecord) — esta view
    não faz query. $status = INSERIDO (itens do pedido) ou REMOVIDO.
--}}
@php
    use App\Support\FormatoQuantidade;

    $pedido = $getRecord();
    $itens = $pedido->item_pedido_pedido_id->where('item_pedido_status', $status);
@endphp

@if ($itens->isEmpty())
    <p class="text-sm text-gray-400">{{ $status === 'REMOVIDO' ? 'Nenhum item removido.' : 'Sem itens.' }}</p>
@else
    <div class="divide-y divide-gray-100 text-sm dark:divide-white/5">
        @foreach ($itens as $item)
            <div class="flex items-start justify-between gap-3 py-2">
                <div class="min-w-0">
                    <div class="text-gray-950 dark:text-white">
                        {{ FormatoQuantidade::item($item->item_pedido_quantidade) }}x
                        <x-item-nome :item="$item" />
                    </div>

                    @foreach ($item->adicionaisItemPedido as $adicional)
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            + {{ (int) $adicional->aip_quantidade }} {{ $adicional->adicional?->adicional_nome }}
                        </p>
                    @endforeach

                    @if ($item->item_pedido_observacao)
                        <p class="text-xs italic text-amber-700 dark:text-amber-400">Obs: {{ $item->item_pedido_observacao }}</p>
                    @endif

                    @if ($status === 'REMOVIDO')
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Removido por {{ $item->usuarioRemoveu?->name_first ?? 'não identificado' }}
                        </p>
                    @endif
                </div>

                <span class="shrink-0 text-gray-700 dark:text-gray-300">
                    @if ($item->item_pedido_cortesia)
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-500/20 dark:text-amber-300">Cortesia</span>
                        @if ($item->item_pedido_cortesia_motivo)
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $item->item_pedido_cortesia_motivo }}</span>
                        @endif
                    @else
                        {{ Number::currency((float) $item->item_pedido_valor, 'BRL', 'pt_BR') }}
                    @endif
                </span>
            </div>
        @endforeach
    </div>
@endif
