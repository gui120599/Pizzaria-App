<section>
    <header>
        <div class="flex justify-between">
            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Pedidos/Itens da Mesa') }}
            </h2>
        </div>
    </header>
    <div class="w-[18rem] sm:w-[99%] overflow-auto mx-auto h-2/4">
        <table class="w-full text-center text-[7px] md:text-base">
            <thead class="">
                <tr class="border-b-4">
                    <th class="px-1 md:px-4">Pedido</th>
                    <th class="px-1 md:px-4">Garçom</th>
                    <th class="px-1 md:px-4">Descrição</th>
                    <th class="px-1 md:px-4">Qtd</th>
                    <th class="px-1 md:px-4">Valor</th>
                    <th class="px-1 md:px-4">Opções</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($itens_inserido_pedido as $item)
                    <tr class="">
                        <td class="">{{ $item->item_pedido_pedido_id }}</td>
                        <td>{{ $item->pedido->garcom->name_first }}</td>
                        <td class=" uppercase">{{ $item->produto->categoria->categoria_nome }}
                            {{ $item->produto->produto_descricao }}
                            @if ($item->item_pedido_observacao)
                                <p class="text-[8px]">{{ $item->item_pedido_observacao }}</p>
                            @endif
                        </td>
                        @if ($item->item_pedido_quantidade == 0.5)
                            <td class="text-xs font-bold text-center">
                                {{ $item->item_pedido_quantidade }} <br>
                                <label class="text-[8px] font-bold text-left">(Meia)</label>
                            </td>
                        @else
                            <td class="text-xs font-bold text-center">{{ $item->item_pedido_quantidade }}</td>
                        @endif
                        <td class=" font-bold">R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}
                        </td>
                        <td>
                            <form action="{{ route('removerItemPedidoMesa', ['item_pedido_id' => $item->id, 'pedido_id' => $item->item_pedido_pedido_id]) }}" method="GET" onsubmit="return confirm('Tem certeza que deseja remover este item?');">
                                @csrf
                                <button type="submit" class="bg-red-500 text-white font-bold py-2 px-4 rounded hover:bg-red-700">Remover</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
            <i class='bx bx-dollar-circle'></i>
            <span>{{ __('Valores') }}</span>
        </p>
        <div class="grid grid-cols-1 lg:grid-cols-3 lg:space-x-2">
            <div class="col-span-1">
                <x-input-label for="pedido_valor_itens" :value="__('Itens R$')" />
                <x-money-input id="pedido_valor_itens" name="pedido_valor_itens" type="text"
                    class="mt-1 w-full" autocomplete="off" value="{{ number_format($pedidos->sum('pedido_valor_itens'), 2, ',', '.') }}" readonly />
            </div>
            <div class="col-span-1">
                <x-input-label for="pedido_valor_desconto" :value="__('Desconto R$')" />
                <x-money-input id="pedido_valor_desconto" name="pedido_valor_desconto" type="text"
                    class="money mt-1 w-full" autocomplete="off" value="{{ number_format($pedidos->sum('pedido_valor_desconto'), 2, ',', '.') }}" readonly />
            </div>
            <div class="col-span-1">
                <x-input-label for="pedido_valor_total" :value="__('Total R$')" />
                <x-money-input id="pedido_valor_total" name="pedido_valor_total" type="text"
                    class="mt-1 w-full" autocomplete="off" value="{{ number_format($pedidos->sum('pedido_valor_total'), 2, ',', '.') }}" readonly />
            </div>
        </div>
    </div>
</section>
