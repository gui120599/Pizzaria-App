<section class="h-full">
    {{-- PRODUTOS --}}
    <div class="h-full flex flex-col justify-between">

        <div>
            <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                <i class='bx bxs-map-pin'></i>
                <span>{{ __('Produtos') }}</span>
            </p>

            <div class="flex flex-col mb-4">
                <x-text-input id="buscar" class="" placeholder="Buscar Produtos"></x-text-input>
                <div class="hidden md:flex gap-2 overflow-auto p-1">
                    @foreach ($categorias as $categoria)
                        <button type="button"
                            class="rolarCategoria inline-flex items-center bg-gray-200 border p-1 border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                            onclick="scrollToElement('categoria_{{ $categoria->id }}')">
                            {{ $categoria->categoria_nome }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="overflow-auto snap-y" id="produtos-container">
            @foreach ($categorias as $categoria)
                <div class="mb-4 cate" id="categoria_{{ $categoria->id }}">
                    <h2 class="text-lg font-bold">{{ $categoria->categoria_nome }}</h2>
                    @if ($categoria->produtos->isEmpty())
                        <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-2 pe-1">
                            @foreach ($categoria->produtos as $produto)
                                <div class="relative snap-end">
                                    <div class="produto cursor-pointer hover:shadow-lg"
                                        data-produto_id="{{ $produto->id }}"
                                        data-produto_valor="{{ $produto->produto_preco_venda }}">
                                        <div
                                            class="w-full flex flex-col bg-gray-100 p-2 rounded-lg opacity-95 hover:opacity-100 gap-1 justify-stretch max-h-40">

                                            <div class="max-h-24 flex flex-col justify-between">
                                                <p
                                                    class="text-gray-900 font-bold text-sm md:text-xs uppercase produto_descricao">
                                                    {{ $produto->categoria->categoria_nome }}
                                                    {{ $produto->produto_descricao }}
                                                </p>
                                                <span class="text-green-500 text-xl">
                                                    R${{ str_replace('.', ',', $produto->produto_preco_venda) }}
                                                </span>
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
</section>
