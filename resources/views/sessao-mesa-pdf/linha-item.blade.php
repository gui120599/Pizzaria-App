{{-- Linha de item da pré-conta de mesa (ItensPedido $item). --}}
<tr>
    @if ($item->item_pedido_quantidade == 0.5)
        <td class="text-xs font-bold text-center align-top">
            {{ $item->item_pedido_quantidade }}<br>
            <span class="text-[8px]">(Meia)</span>
        </td>
    @else
        <td class="text-xs font-bold text-center align-top">{{ $item->item_pedido_quantidade }}</td>
    @endif
    <td class="text-xs text-center uppercase align-top">
        <x-impressao.nome-item :item="$item" />
        @foreach ($item->adicionaisItemPedido as $adicional)
            <p class="text-[8px] font-bold">{{ $adicional->aip_quantidade }} Adic. {{ $adicional->adicional->adicional_nome }}</p>
        @endforeach
        @if ($item->item_pedido_observacao)
            <p class="text-[8px] font-bold">Obs: {{ $item->item_pedido_observacao }}</p>
        @endif
        @if ($item->item_pedido_desconto > 0)
            <p class="text-[8px] font-bold normal-case">Promoção: você economizou R$ {{ number_format($item->item_pedido_desconto, 2, ',', '.') }}</p>
        @endif
    </td>
    <td class="text-[9px] font-bold text-right align-top">
        @if ($item->item_pedido_desconto > 0)
            @php $valorSemDesconto = $item->item_pedido_valor + $item->item_pedido_desconto; @endphp
            <span class="text-[8px] font-normal line-through">R$ {{ number_format($valorSemDesconto, 2, ',', '.') }}</span><br>
            R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}
        @else
            R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}
        @endif
    </td>
</tr>
