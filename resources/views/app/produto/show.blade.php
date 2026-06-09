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
                <img src="{{ $produto->getImagemUrl() }}"
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

                    <div class="flex gap-2 flex-wrap">
                        @if ($ehMaisVendido ?? false)
                            <span class="bg-yellow-500 text-white text-xs font-bold px-2 py-0.5 rounded uppercase flex items-center gap-1">
                                <i class='bx bxs-star text-xs'></i> Mais Vendido
                            </span>
                        @endif
                        @if ($produto->produto_preco_promocional > 0)
                            <span class="bg-orange-500 text-white text-xs font-bold px-2 py-0.5 rounded uppercase flex items-center gap-1">
                                <i class='bx bxs-purchase-tag text-xs'></i> Em Promoção
                            </span>
                        @endif
                    </div>

                    @if ($produto->produto_preco_promocional > 0)
                    <div class="flex flex-col">
                        <span class="text-gray-400 text-sm line-through">DE: R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                        <span class="text-green-400 text-2xl font-bold">POR: R${{ str_replace('.', ',', $produto->produto_preco_promocional) }}</span>
                    </div>
                    @else
                    <div class="flex items-center">
                        <span class="text-white text-lg font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                    </div>
                    @endif
                    <div x-data="{
                        adicionarEAbrirPedido() {
                            const cart = JSON.parse(localStorage.getItem('cardapio_cart') || '[]');
                            const key  = String({{ $produto->id }});
                            const idx  = cart.findIndex(i => i.cartKey === key);
                            if (idx >= 0) {
                                cart[idx].qty++;
                            } else {
                                cart.push({
                                    cartKey:       key,
                                    id:            {{ $produto->id }},
                                    nome:          @js($produto->categoria->categoria_nome . ' ' . $produto->produto_descricao),
                                    preco:         {{ $produto->produto_preco_promocional > 0 ? (float) $produto->produto_preco_promocional : (float) $produto->produto_preco_venda }},
                                    precoOriginal: {{ (float) $produto->produto_preco_venda }},
                                    qty:           1,
                                    foto:          @js($produto->getImagemUrl()),
                                    obs:           ''
                                });
                            }
                            localStorage.setItem('cardapio_cart', JSON.stringify(cart));
                            window.location.href = @js(route('cardapio')) + '?abrir=carrinho';
                        }
                    }">
                        <x-primary-button @click="adicionarEAbrirPedido()"
                            class="w-full flex items-center justify-center space-x-1">
                            <i class='bx bxl-whatsapp'></i><span>Realizar Pedido</span>
                        </x-primary-button>
                    </div>
                </div>
                <!-- Adicione mais detalhes conforme necessário -->
            </div>
        </div>
    </div>
</x-guest-layout>
