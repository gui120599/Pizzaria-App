<section class="h-full space-y-2">
    <div class="overflow-auto mx-auto md:h-[5%] ">
        <div class="flex flex-col md:flex-row justify-between p-3 md:p-0 space-y-2 md:space-y-0">
            <div class="flex md:hidden space-x-2 items-center">
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('Pedidos e Itens da ' . $mesa->mesa_nome) }}
                </h2>
            </div>
            <div class="flex space-x-2">
                <x-primary-link
                    href="{{ route('sessaoMesa.pedidoMesa', ['mesa_id' => $sessao_mesa->sessao_mesa_mesa_id]) }}"><i
                        class='bx bx-plus'></i>NOVO PEDIDO</x-primary-link>
                @php
                    $openPedidosExistentes = request('page_PedidosExistentes') > 0;
                    $openRemoverPedidos = request('page_RemoverPedidos') > 0;
                @endphp

                <x-secondary-button x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'seleciona-pedido')"><i class='bx bxs-plus-circle'></i>
                    {{ __('Adicionar pedido existente') }}</x-secondary-button>

                <x-danger-button x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'remover-pedido')"><i class='bx bx-minus-circle'></i>
                    {{ __('Remover pedido da mesa') }}</x-danger-button>

                <x-modal name="seleciona-pedido" :show="$openPedidosExistentes" :maxWidth="'6xl'">

                    <form method="POST"
                        action="{{ route('sessaoMesa.updateAdicionarExistentes', ['sessaoMesa' => $sessao_mesa]) }}"
                        class="p-6">
                        <div class="border-b-2 flex justify-between mb-2 p-2">
                            <div class="">
                                <h2 class="text-lg font-medium text-gray-900">
                                    {{ __('Pedidos disponíveis') }}
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    {{ __('Lista de pedidos que estão com status ABERTO, PREPARANDO, PRONTO ou ENTREGUE.') }}
                                </p>
                            </div>
                            <x-secondary-button x-on:click="$dispatch('close')">
                                <i class='bx bx-x text-lg'></i>
                            </x-secondary-button>
                        </div>

                        @csrf
                        @method('patch')
                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 pe-3 py-3 h-[70vh] overflow-auto">

                            @foreach ($pedidosExistentes as $pedido)
                                <div
                                    class="col-span-1 border-2 p-1 rounded-lg w-full shadow-sm flex flex-col justify-between">
                                    <div>
                                        <div class="space-y-1">
                                            <div class="grid grid-cols-3 space-x-1">
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <div
                                                        class="flex items-center justify-center space-x-2 w-full text-center text-sm rounded-lg p-1 font-bold">
                                                        <input id="pedido-{{ $pedido->id }}" name="pedidoExistente[]"
                                                            type="checkbox" value="{{ $pedido->id }}"
                                                            class="peer hidden">
                                                        <label for="pedido-{{ $pedido->id }}"
                                                            class="flex-1 p-1 rounded-lg border-2  peer-checked:bg-gradient-to-r peer-checked:from-green-400 peer-checked:to-green-600 text-gray-900 cursor-pointer">
                                                            PEDIDO: {{ $pedido->id }}
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase">STATUS</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">
                                                        {{ $pedido->pedido_status }}
                                                    </span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase"><i class='bx bx-calendar'></i>
                                                        Data/Hora</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">{{ \Carbon\Carbon::parse($pedido->pedido_datahora_abertura)->format('d/m/y H:i') }}</span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px]">Garçom</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->garcom->name_first }}</span>
                                                </div>
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <span class="text-[7px]">Mesa</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="itens-pedido">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <div id="Tabela">
                                                <table class="w-full">
                                                    <thead>
                                                        <tr class="text-[8px] bg-emerald-500 text-white rounded-lg p-1">
                                                            <th>QTD</th>
                                                            <th class="max-w-max">PRODUTO</th>
                                                            <th>VALOR</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($pedido->produtosInseridosPedido as $item)
                                                            @if ($item->pivot->item_pedido_observacao)
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }} -
                                                                        <span
                                                                            class="font-bold">{{ $item->pivot->item_pedido_observacao }}</span>
                                                                    </td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @else
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }}</td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @endif
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    @if ($pedido->pedido_venda_id)
                                        <div class="flex items-center">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <span class="text-center">Pedido Já Pago! Venda:
                                                {{ $pedido->pedido_venda_id }}</span>
                                        </div>
                                    @endif

                                    <div>
                                        <hr class="h-px my-1 border-0 bg-gray-200">
                                        <div class="flex justify-between">
                                            <span>Valor do Pedido</span>
                                            <span class="font-bold">R$
                                                {{ str_replace('.', ',', $pedido->pedido_valor_total) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6 flex justify-between">
                            <div class="">
                                {{ $pedidosExistentes->links() }}
                            </div>
                            <div>
                                <x-secondary-button x-on:click="$dispatch('close')">
                                    {{ __('Cancel') }}
                                </x-secondary-button>

                                <x-primary-button class="ms-3">
                                    {{ __('Adicionar pedidos selecionados') }}
                                </x-primary-button>
                            </div>
                        </div>
                    </form>
                </x-modal>
                <x-modal name="remover-pedido" :show="$openRemoverPedidos" :maxWidth="'6xl'">

                    <form method="POST"
                        action="{{ route('sessaoMesa.updateRemoverPedidosSessaoMesa', ['sessaoMesa' => $sessao_mesa]) }}"
                        class="p-6">
                        <div class="border-b-2 flex justify-between mb-2 p-2">
                            <div class="">
                                <h2 class="text-lg font-medium text-gray-900">
                                    {{ __('Pedidos disponíveis') }}
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    {{ __('Lista de pedidos que estão na ' . $mesa->mesa_nome . ', e podem ser removidos.') }}
                                </p>
                            </div>
                            <x-secondary-button x-on:click="$dispatch('close')">
                                <i class='bx bx-x text-lg'></i>
                            </x-secondary-button>
                        </div>

                        @csrf
                        @method('patch')
                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 pe-3 py-3 h-[70vh] overflow-auto">

                            @foreach ($pedidosRemover as $pedido)
                                <div
                                    class="col-span-1 border-2 p-1 rounded-lg w-full shadow-sm flex flex-col justify-between">
                                    <div>
                                        <div class="space-y-1">
                                            <div class="grid grid-cols-3 space-x-1">
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <div
                                                        class="flex items-center justify-center space-x-2 w-full text-center text-sm rounded-lg p-1 font-bold">
                                                        <input id="pedido-{{ $pedido->id }}" name="pedidoExistente[]"
                                                            type="checkbox" value="{{ $pedido->id }}"
                                                            class="peer hidden">
                                                        <label for="pedido-{{ $pedido->id }}"
                                                            class="flex-1 p-1 rounded-lg border-2  peer-checked:bg-gradient-to-r peer-checked:from-green-400 peer-checked:to-green-600 text-gray-900 cursor-pointer">
                                                            PEDIDO: {{ $pedido->id }}
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase">STATUS</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">
                                                        {{ $pedido->pedido_status }}
                                                    </span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase"><i class='bx bx-calendar'></i>
                                                        Data/Hora</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">{{ \Carbon\Carbon::parse($pedido->pedido_datahora_abertura)->format('d/m/y H:i') }}</span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px]">Garçom</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->garcom->name_first }}</span>
                                                </div>
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <span class="text-[7px]">Mesa</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="itens-pedido">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <div id="Tabela">
                                                <table class="w-full">
                                                    <thead>
                                                        <tr
                                                            class="text-[8px] bg-emerald-500 text-white rounded-lg p-1">
                                                            <th>QTD</th>
                                                            <th class="max-w-max">PRODUTO</th>
                                                            <th>VALOR</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($pedido->produtosInseridosPedido as $item)
                                                            @if ($item->pivot->item_pedido_observacao)
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }} -
                                                                        <span
                                                                            class="font-bold">{{ $item->pivot->item_pedido_observacao }}</span>
                                                                    </td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @else
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }}</td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @endif
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    @if ($pedido->pedido_venda_id)
                                        <div class="flex items-center">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <span class="text-center">Pedido Já Pago! Venda:
                                                {{ $pedido->pedido_venda_id }}</span>
                                        </div>
                                    @endif

                                    <div>
                                        <hr class="h-px my-1 border-0 bg-gray-200">
                                        <div class="flex justify-between">
                                            <span>Valor do Pedido</span>
                                            <span class="font-bold">R$
                                                {{ str_replace('.', ',', $pedido->pedido_valor_total) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6 flex justify-between">
                            <div class="">
                                {{ $pedidosRemover->links() }}
                            </div>
                            <div>
                                <x-secondary-button x-on:click="$dispatch('close')">
                                    {{ __('Cancel') }}
                                </x-secondary-button>

                                <x-danger-button class="ms-3">
                                    {{ __('Remover pedidos selecionados') }}
                                </x-danger-button>
                            </div>
                        </div>
                    </form>
                </x-modal>
            </div>
            <div class="hidden md:flex space-x-2">
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('Pedidos e Itens da ' . $mesa->mesa_nome) }}
                </h2>
            </div>
            <div class="flex space-x-2">
                <x-primary-link
                    href="{{ route('sessaoMesa.editAlterarMesa', ['sessaoMesa' => $sessao_mesa]) }}">alterar
                    mesa</x-primary-link>
                <x-secondary-button id="btn-imprimir">IMPRIMIR</x-secondary-button>
                <form action="{{ route('sessaoMesa.fechar', ['sessaoMesa' => $sessao_mesa]) }}" method="get">
                    @method('patch')
                    <x-primary-button>FECHAR MESA</x-primary-button>
                </form>
            </div>
        </div>
    </div>
    <div class="overflow-auto w-full md:mx-auto md:h-[95%] flex flex-col">
        {{-- VALORES --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 space-y-2 md:space-y-0 lg:space-x-2">
            {{-- TOTAL --}}
            <div class="col-span-1 border rounded-xl shadow-lg p-2 bg-gradient-to-l from-green-500 to-amber-100">
                <div class="grid grid-cols-3">
                    <div class="col-span-1 flex flex-col items-start md:items-end">
                        <span class="text-[9px] md:text-base font-bold">VALOR TOTAL</span>
                        <span class="text-2xl text-gray-400">R$</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center">
                        <span
                            class="text-4xl font-bold text-end">{{ number_format($pedidos->sum('pedido_valor_total'), 2, ',', '.') }}</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center max-h-5 object-cover">
                        <i class='bx bx-dollar text-6xl md:text-9xl text-gray-200'></i>
                    </div>
                </div>
            </div>
            {{-- ITENS --}}
            <div class="col-span-1 border rounded-xl shadow-lg p-2 bg-gradient-to-l from-amber-500 to-amber-100">
                <div class="grid grid-cols-3">
                    <div class="col-span-1 flex flex-col items-start md:items-end">
                        <span class="text-[9px] md:text-base font-bold">VALOR ITENS</span>
                        <span class="text-2xl text-gray-400">R$</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center">
                        <span
                            class="text-4xl font-bold text-end">{{ number_format($pedidos->sum('pedido_valor_itens'), 2, ',', '.') }}</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center max-h-5 object-cover">
                        <i class='bx bxs-pizza text-6xl md:text-9xl text-gray-200'></i>
                    </div>
                </div>
            </div>
            {{-- DESCONTO --}}
            <div class="col-span-1 border rounded-xl shadow-lg p-2 bg-gradient-to-l from-blue-500 to-amber-100">
                <div class="grid grid-cols-3">
                    <div class="col-span-1 flex flex-col items-start md:items-end">
                        <span class="text-[9px] md:text-base font-bold">VALOR DESCONTO</span>
                        <span class="text-2xl text-gray-400">R$</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center">
                        <span
                            class="text-4xl font-bold text-end">{{ number_format($pedidos->sum('pedido_valor_desconto'), 2, ',', '.') }}</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center max-h-5 object-cover">
                        <i class='bx bxs-badge-dollar text-6xl md:text-9xl text-gray-200 '></i>
                    </div>
                </div>
            </div>
        </div>
        {{-- PEDIDOS --}}
        <div class="overflow-auto">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 pe-3 py-3">
                @foreach ($pedidos as $pedido)
                    <div class="col-span-1 border-2 p-1 rounded-lg w-full shadow-sm flex flex-col justify-between">
                        <div>
                            <div class="space-y-1">
                                <div class="grid grid-cols-3 space-x-1">
                                    <div class="col-span-3 flex flex-col items-center">
                                        <span
                                            class="w-full text-center text-sm bg-gradient-to-r to-teal-600 from-teal-400 rounded-lg p-1 text-white font-bold">PEDIDO:
                                            {{ $pedido->id }}</span>
                                    </div>
                                    <div class="col-span-1 flex flex-col items-center">
                                        <span class="text-[7px] uppercase">STATUS</span>
                                        <span
                                            class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">
                                            {{ $pedido->pedido_status }}
                                        </span>
                                    </div>
                                    <div class="col-span-1 flex flex-col items-center">
                                        <span class="text-[7px] uppercase"><i class='bx bx-calendar'></i>
                                            Data/Hora</span>
                                        <span
                                            class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">{{ \Carbon\Carbon::parse($pedido->pedido_datahora_abertura)->format('d/m/y H:i') }}</span>
                                    </div>
                                    <div class="col-span-1 flex flex-col items-center">
                                        <span class="text-[7px]">Garçom</span>
                                        <span
                                            class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->garcom->name_first }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="itens-pedido">
                                <hr class="h-px my-1 border-0 bg-gray-200">
                                <div id="Tabela">
                                    <table class="w-full">
                                        <thead>
                                            <tr class="text-[8px] bg-emerald-500 text-white rounded-lg p-1">
                                                <th>QTD</th>
                                                <th class="max-w-max">PRODUTO</th>
                                                <th>VALOR</th>
                                                <th>EXCLUIR</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($pedido->produtosInseridosPedido as $item)
                                                @if ($item->pivot->item_pedido_observacao)
                                                    <tr>
                                                        <td class="text-xs font-bold text-center">
                                                            {{ $item->pivot->item_pedido_quantidade }}</td>
                                                        <td class="text-xs text-center uppercase">
                                                            {{ $item->categoria->categoria_nome }}
                                                            {{ $item->produto_descricao }} -
                                                            <span
                                                                class="font-bold">{{ $item->pivot->item_pedido_observacao }}</span>
                                                        </td>
                                                        <td class="text-xs font-bold text-right">R$
                                                            {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                        </td>
                                                        <td>
                                                            <form
                                                                action="{{ route('removerItemPedidoMesa', ['item_pedido_id' => $item->pivot->id, 'pedido_id' => $pedido->id]) }}"
                                                                method="GET"
                                                                onsubmit="return confirm('Tem certeza que deseja remover este item?');">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="bg-white font-bold text-xl p-1 w-full"
                                                                    title="Excluir Item"><i
                                                                        class='text-red-500 bx bxs-x-circle'></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @else
                                                    <tr>
                                                        <td class="text-xs font-bold text-center">
                                                            {{ $item->pivot->item_pedido_quantidade }}</td>
                                                        <td class="text-xs text-center uppercase">
                                                            {{ $item->categoria->categoria_nome }}
                                                            {{ $item->produto_descricao }}</td>
                                                        <td class="text-xs font-bold text-right">R$
                                                            {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                        </td>
                                                        <td class="text-center p-1">
                                                            <form
                                                                action="{{ route('removerItemPedidoMesa', ['item_pedido_id' => $item->pivot->id, 'pedido_id' => $pedido->id]) }}"
                                                                method="GET"
                                                                onsubmit="return confirm('Tem certeza que deseja remover este item?');">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="bg-white font-bold text-xl p-1 w-full"
                                                                    title="Excluir Item"><i
                                                                        class='text-red-500 bx bxs-x-circle'></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        @if ($pedido->pedido_venda_id)
                            <div class="flex items-center">
                                <hr class="h-px my-1 border-0 bg-gray-200">
                                <span class="text-center">Pedido Já Pago! Venda: {{ $pedido->pedido_venda_id }}</span>
                            </div>
                        @endif

                        <div>
                            <hr class="h-px my-1 border-0 bg-gray-200">
                            <div class="flex justify-between">
                                <span>Valor do Pedido</span>
                                <span class="font-bold">R$
                                    {{ str_replace('.', ',', $pedido->pedido_valor_total) }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <script type="module">
        $("#btn-imprimir").click(function(e) {
            e.preventDefault();
            window.open('{{ route('sessaoMesa.imprimir', ['id' => $sessao_mesa->id]) }}', 'Teste',
                'width=600,height=400');
        });
    </script>
</section>
