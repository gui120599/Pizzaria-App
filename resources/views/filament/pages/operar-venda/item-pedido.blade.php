{{--
    Linha de um item de pedido nas abas Mesas e Pedidos avulsos: checkbox que
    lança/retira só este item, botão para lançar parte da quantidade e selo
    quando o item já está em outra venda.
--}}
@php
    /** @var \App\Models\ItensPedido $itemPedido */
    $situacao = $this->situacaoDoItem($itemPedido);
@endphp
<div wire:key="item-pedido-{{ $itemPedido->id }}" @class([
    'flex items-center gap-2 text-xs',
    'text-gray-600 dark:text-gray-300' => in_array($situacao, ['livre', 'nesta'], true),
    'text-gray-400 dark:text-gray-500' => in_array($situacao, ['outra', 'paga'], true),
])>
    <input
        type="checkbox"
        wire:loading.attr="disabled"
        @checked($situacao === 'nesta')
        @disabled(in_array($situacao, ['outra', 'paga'], true))
        x-on:change="$wire.alternarItemPedido({{ $itemPedido->id }})"
        class="h-3.5 w-3.5 shrink-0 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500 disabled:opacity-40"
    />
    <span class="min-w-0 flex-1">
        {{ rtrim(rtrim(number_format((float) $itemPedido->item_pedido_quantidade, 3, ',', '.'), '0'), ',') }}x <x-item-nome :item="$itemPedido" />
        @if (($mostrarPessoa ?? false) && $itemPedido->cliente)
            <span class="ml-1 inline-flex rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $itemPedido->cliente->cliente_nome }}</span>
        @endif
        @if ($situacao === 'outra')
            <span class="ml-1 inline-flex rounded bg-warning-50 px-1.5 py-0.5 text-[10px] font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">venda #{{ $itemPedido->item_pedido_venda_id }}</span>
        @elseif ($situacao === 'paga')
            <span class="ml-1 inline-flex rounded bg-success-50 px-1.5 py-0.5 text-[10px] font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">pago</span>
        @endif
    </span>
    @if ($this->podeDividirItem($itemPedido))
        <button type="button"
            wire:click="mountAction('dividirItem', { item: {{ $itemPedido->id }} })"
            title="Lançar só algumas unidades"
            class="shrink-0 text-[11px] font-semibold text-primary-600 hover:underline dark:text-primary-400">
            Dividir
        </button>
    @endif
    <span class="shrink-0">R$ {{ number_format((float) $itemPedido->item_pedido_valor, 2, ',', '.') }}</span>
</div>
