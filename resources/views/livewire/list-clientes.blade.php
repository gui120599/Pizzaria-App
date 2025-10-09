<div class="overflow-auto">
    <!-- GRID DE PRODUTOS -->
    <div class="mt-4">
        <!-- Produtos carregados -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2 py-1">
            <p wire:loading wire:target="FiltrarProdutosCategoriaId"
                class="col-span-full text-center text-gray-400 text-sm py-4">
                <i class='bx bx-loader-circle bx-spin text-3xl mb-2 text-gray-500'></i><br>
                <span>Carregando produtos...</span>
            </p>
            @if (empty($produtos) || $produtos->isEmpty())
                <p wire:loading.remove wire:target="FiltrarProdutosCategoriaId"
                    class="col-span-full text-center text-gray-400 text-sm py-4">
                    Selecione uma categoria para visualizar os produtos.
                </p>
            @else
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
                            <div class="max-h-24 flex flex-col justify-between">
                                <p class="text-[8px] font-extralight">{{ $produto->categoria->categoria_nome }}</p>
                                <p class="text-gray-900 font-bold">{{ $produto->produto_descricao }}</p>

                                <span class="text-green-500 text-sm">
                                    R${{ str_replace('.', ',', $produto->produto_preco_venda) }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</div>
