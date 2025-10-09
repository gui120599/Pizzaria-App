<div>
    <!-- Título -->
    <div class="bg-white p-4 rounded-lg sticky top-0 z-50 shadow-md">
        <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
            <i class='bx bxs-map-pin'></i>
            <span>{{ __('Filtrar Produtos') }}</span>
        </p>

        <!-- Botões de Categorias -->
        <div class="flex-row md:flex mb-4 gap-x-2">
            <div class="w-full md:w-1/4 mt-1 mx-1">
                <x-text-input-buscar class="w-full" placeholder="Buscar Produtos"
                    wire:model.live.debounce.500ms="search" />
            </div>

            <div class="flex w-full md:w-9/12 gap-x-2 overflow-auto snap-y p-1">
                <x-secondary-button type="button"  wire:click="top10MaisPedidos">🔥 Top 10 mais pedidos</x-secondary-button>
                @foreach ($categorias->where('categoria_cardapio', true)->get(['id', 'categoria_nome']) as $categoria)
                    <x-secondary-button type="button"
                        wire:click="FiltrarProdutosCategoriaId({{ $categoria->id }})">{{ $categoria->categoria_nome }}</x-secondary-button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Produtos -->
    <div class="overflow-auto">
        <!-- GRID DE PRODUTOS -->
        <div class="mt-4">
            <!-- Produtos carregados -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 py-1">

                <!-- Loading -->
                <p wire:loading wire:target="FiltrarProdutosCategoriaId"
                    class="col-span-full text-center text-gray-400 text-sm py-4">
                    <i class='bx bx-loader-circle bx-spin text-3xl mb-2 text-gray-500'></i><br>
                    <span>Carregando produtos...</span>
                </p>

                <!-- Se não houver produtos -->
                @if ($modoMaisPedidos)
                    <p class="col-span-full text-gray-600 text-sm">
                        🔥 Top 10 produtos mais pedidos recentemente
                    </p>
                    @foreach ($produtos as $produto)
                        <div wire:loading.remove wire:target="FiltrarProdutosCategoriaId"
                            class="relative snap-end transition-transform duration-300 hover:scale-[1.02] cursor-pointer">
                            <div
                                class="w-full flex flex-col bg-gray-100 p-2 rounded-lg opacity-95 hover:opacity-100 gap-1 justify-stretch max-h-60">

                                <!-- Imagem -->
                                <div class="w-full">
                                    @if ($produto->produto_foto)
                                        <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                            alt="{{ $produto->produto_descricao }}"
                                            class="w-full max-h-32 object-cover rounded-lg">
                                    @else
                                        <img src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão"
                                            class="w-full max-h-32 object-cover rounded-lg">
                                    @endif
                                </div>

                                <!-- Informações -->
                                <div class="h-full flex flex-col justify-between">
                                    <p class="text-[8px] font-light">{{ $produto->categoria->categoria_nome }}</p>
                                    <p class="text-gray-900 text-sm font-bold uppercase">
                                        {{ $produto->produto_descricao }}</p>
                                    <span class="text-green-500 text-sm">
                                        R${{ str_replace('.', ',', $produto->produto_preco_venda) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @elseif(!empty($produtos))
                    @if ($categoria_id)
                        <p wire:target="FiltrarProdutosCategoriaId"
                            class="col-span-full text-gray-600 text-sm">
                            {{ $categorias->find($categoria_id)->categoria_nome }}
                        </p>
                    @endif

                    @foreach ($produtos as $produto)
                        <div wire:loading.remove wire:target="FiltrarProdutosCategoriaId"
                            class="relative snap-end transition-transform duration-300 hover:scale-[1.02] cursor-pointer">
                            <div
                                class="w-full flex flex-col bg-gray-100 p-2 rounded-lg opacity-95 hover:opacity-100 gap-1 justify-stretch max-h-60">

                                <!-- Imagem -->
                                <div class="w-full">
                                    @if ($produto->produto_foto)
                                        <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                                            alt="{{ $produto->produto_descricao }}"
                                            class="w-full max-h-32 object-cover rounded-lg">
                                    @else
                                        <img src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão"
                                            class="w-full max-h-32 object-cover rounded-lg">
                                    @endif
                                </div>

                                <!-- Informações -->
                                <div class="h-full flex flex-col justify-between">
                                    <p class="text-[8px] font-light">{{ $produto->categoria->categoria_nome }}</p>
                                    <p class="text-gray-900 text-sm font-bold uppercase">
                                        {{ $produto->produto_descricao }}</p>
                                    <span class="text-green-500 text-sm">
                                        R${{ str_replace('.', ',', $produto->produto_preco_venda) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @elseif (empty($produtos) || $produtos->isEmpty())
                    <p class="col-span-full text-center text-gray-400 text-sm py-4">
                        Selecione uma categoria ou veja os produtos mais pedidos abaixo.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
