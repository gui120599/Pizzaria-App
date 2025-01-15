<x-guest-layout>
    <div class="h-[100vh] w-full md:max-w-lg mx-auto">
        <div class="h-[10%] flex items-center">
            <a href="{{ route('cardapio') }}">
                <i class='bx bx-chevron-left text-gray-700 hover:text-gray-400 font-bold text-5xl'></i>
            </a>
        </div>
        <div class="w-full h-full mx-auto sm:px-1 lg:px-2 space-y-6">
            <div class="h-full w-full p-1 rounded-lg shadow-md">
                @if ($produto->produto_foto)
                <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}"
                alt="{{ $produto->produto_nome }}" class="w-full h-[50%] object-cover mb-4 rounded-2xl">
                @else
                    <img id="imagem-preview" class="bg-white w-full h-[50%] object-cover mb-4 rounded-2xl"
                        src="{{ asset('Sem Imagem.png') }}" alt="Imagem Padrão">
                @endif
                <div class="w-full flex flex-col justify-center space-y-2">
                    <h2 class="text-gray-100 text-lg uppercase">
                        @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                            {{ $produto->produto_descricao }} - <span>Ref.
                                {{ $produto->produto_referencia }}</span>
                        @else
                            {{ $produto->categoria->categoria_nome }} {{ $produto->produto_descricao }}
                        @endif
                    </h2>

                    <div class="flex items-center">
                        <span class="text-gray-200 text-xs ">Codimentos:
                            {{ $produto->produto_codimentacao }}</span>
                    </div>

                    <div class="flex items-center">
                        <span
                            class="text-white text-lg font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                    </div>
                    <a class="w-full"
                        href="https://web.whatsapp.com/send?phone=5564981453615&text=Ol%C3%A1%21+Gostaria+de+realizar+um+pedido%F0%9F%98%81">
                        <x-primary-button class="w-full flex items-center justify-center space-x-1"><i
                                class='bx bxl-whatsapp'></i><span>Realizar Pedido</span></x-primary-button>
                    </a>
                </div>
                <!-- Adicione mais detalhes conforme necessário -->
            </div>
        </div>
    </div>
</x-guest-layout>
