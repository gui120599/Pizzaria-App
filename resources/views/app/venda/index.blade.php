<x-app-layout>
    <div class="mx-auto p-2 ">
        <div class="p-2 min-h-[97vh] bg-white shadow-sm rounded-lg flex">
            <div class="w-[80%] mx-auto pe-2 flex flex-col">
                <div class="secao-filtro w-full flex space-x-2">
                    <x-secondary-button class="flex-1">
                        {{ __('Produtos') }}
                    </x-secondary-button>
                    <x-secondary-button class="flex-1">
                        {{ __('Mesas') }}
                    </x-secondary-button>
                    <x-secondary-button class="flex-1">
                        {{ __('Pedidos') }}
                    </x-secondary-button>
                </div>
                <div class="w-full h-[89dvh] overflow-auto">
                    <div class="secao-produtos ">
                        <p>Produtos</p>
                        @foreach ($categorias as $categoria)
                            <div class="mb-4" id="categoria_{{ $categoria->id }}">
                                <h2 class="text-white text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                                @if ($categoria->produtos->isEmpty())
                                    <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                                @else
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-6 gap-4 ">
                                        @foreach ($categoria->produtos as $produto)
                                            <div class="relative snap-end ">
                                                <div
                                                    class="w-full bg-gray-100 p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1">
                                                    <div class="w-2/5">
                                                        @if ($produto->produto_foto)
                                                            <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                                                alt="{{ $produto->produtso_descricao }}"
                                                                class="h-14 object-cover rounded-lg ">
                                                        @else
                                                            <img id="imagem-preview"
                                                                class="h-14 object-cover rounded-lg "
                                                                src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                                                        @endif
                                                    </div>
                                                    <div class="w-3/5 flex flex-col justify-center">
                                                        <h2 class="text-gray-900 text-[8px] uppercase">
                                                            @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                                                                {{ $produto->produto_descricao }} - <span>Ref.
                                                                    {{ $produto->produto_referencia }}</span>
                                                            @else
                                                                {{ $produto->produto_descricao }}
                                                            @endif
                                                        </h2>
                                                        <div class="flex items-center">
                                                            <span
                                                                class="text-gray-900 text-sm font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="secao-mesas hidden">
                        <p>Mesas com Pedidos</p>
                        @foreach ($sessaoMesas as $sessaoMesa)
                            <div class="w-full border border-gray-500 p-3 flex justify-between">
                                <span>{{ $sessaoMesa->mesa->mesa_nome }}</span>
                                <i class='bx bx-chevron-up rotate-180'></i>
                            </div>
                            @php
                                $itensAgrupados = [];

                                // Agrupa itens de todos os pedidos por mesa
                                foreach ($sessaoMesa->pedidos as $pedido) {
                                    foreach ($pedido->item_pedido_pedido_id as $item) {
                                        $produtoId = $item->item_pedido_produto_id;

                                        if (!isset($itensAgrupados[$produtoId])) {
                                            $itensAgrupados[$produtoId] = [
                                                'produto' => $item->produto,
                                                'total_quantidade' => 0,
                                                'total_desconto' => 0,
                                                'total_valor' => 0,
                                            ];
                                        }

                                        $itensAgrupados[$produtoId]['total_quantidade'] += $item->item_pedido_quantidade;
                                        $itensAgrupados[$produtoId]['total_desconto'] += $item->item_pedido_desconto;
                                        $itensAgrupados[$produtoId]['total_valor'] += $item->item_pedido_valor;
                                    }
                                }
                            @endphp

                            <p> Pedidos:
                                @foreach ($sessaoMesa->pedidos as $pedido)
                                {{ $pedido->id }}
                                @endforeach
                            </p>

                            <table class="w-full text-center text-[7px] md:text-base">
                                <thead>
                                    <tr class="border-b-4">
                                        <th class="px-1 md:px-4">#</th>
                                        <th class="px-1 md:px-4">Produto</th>
                                        <th class="px-1 md:px-4">Qtd</th>
                                        <th class="px-1 md:px-4">Valor Desc.</th>
                                        <th class="px-1 md:px-4">Valor Unt.</th>
                                        <th class="px-1 md:px-4">Valor Total</th>
                                        <th class="px-1 md:px-4">Opções</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($itensAgrupados as $produtoId => $item)
                                        <tr>
                                            <td>{{ $item['produto']->id }}</td>
                                            <td>{{ $item['produto']->produto_descricao }}</td>
                                            <td>{{ $item['total_quantidade'] }}</td>
                                            <td>{{ $item['total_desconto'] }}</td>
                                            <td>{{ $item['produto']->produto_preco_venda }}</td>
                                            <td>{{ $item['total_valor'] }}</td>
                                            <td></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endforeach
                    </div>
                    <div class="secao-pedidos hidden">
                        <p>Produtos</p>
                        @foreach ($categorias as $categoria)
                            <div class="mb-4" id="categoria_{{ $categoria->id }}">
                                <h2 class="text-white text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                                @if ($categoria->produtos->isEmpty())
                                    <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                                @else
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 lg:grid-cols-6 gap-4 ">
                                        @foreach ($categoria->produtos as $produto)
                                            <div class="relative snap-end ">
                                                <div
                                                    class="w-full bg-gray-100 p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1">
                                                    <div class="w-2/5">
                                                        @if ($produto->produto_foto)
                                                            <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                                                alt="{{ $produto->produtso_descricao }}"
                                                                class="h-14 object-cover rounded-lg ">
                                                        @else
                                                            <img id="imagem-preview"
                                                                class="h-14 object-cover rounded-lg "
                                                                src="{{ asset('Sem Imagem.png') }}"
                                                                alt="Imagem Padrão">
                                                        @endif
                                                    </div>
                                                    <div class="w-3/5 flex flex-col justify-center">
                                                        <h2 class="text-gray-900 text-[8px] uppercase">
                                                            @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                                                                {{ $produto->produto_descricao }} - <span>Ref.
                                                                    {{ $produto->produto_referencia }}</span>
                                                            @else
                                                                {{ $produto->produto_descricao }}
                                                            @endif
                                                        </h2>
                                                        <div class="flex items-center">
                                                            <span
                                                                class="text-gray-900 text-sm font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="w-[20%] border border-black">
                teset 2
            </div>
        </div>
    </div>
    <script type="module">
        $(document).ready(function() {
            $(".toggleSideBar").trigger("click");
        });
    </script>
</x-app-layout>
