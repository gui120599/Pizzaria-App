<x-guest-layout>
    <div class="h-[100vh] w-full md:max-w-lg mx-auto">
        <div class="h-[20%] grid grid-cols-7">
            <div class="col-span-2">
                <img src="{{ asset('img/logo Pizzaria Branco Colorido.png') }}" alt="" class="h-16">
            </div>
            <div class="col-span-4 flex items-center">
                <a class="w-full"
                    href="https://wa.me/5564981380071">
                    <x-primary-button class="w-full flex items-center justify-center space-x-1"><i
                            class='bx bxl-whatsapp'></i><span>Realizar Pedido</span></x-primary-button>
                </a>
            </div>
            <div class="col-span-1 flex items-center justify-center">
                <button class="text-white text-3xl mt-2"><i class='bx bx-dots-vertical-rounded'></i></button>
            </div>
            <div class="col-span-full">
                <hr class="h-px my-1 border-0 bg-gray-400">
            </div>
            <div class="col-span-full flex gap-2 overflow-x-auto overflow-y-hidden p-1">
                @if ($promocoes->isNotEmpty())
                    <button
                        class="inline-flex items-center gap-1 px-2 py-1 bg-orange-500 border border-orange-600 rounded-md font-semibold text-[10px] text-white uppercase tracking-widest shadow-sm hover:bg-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        onclick="scrollToElement('secao_promocoes')">
                        <i class='bx bxs-purchase-tag'></i> Promoções
                    </button>
                @endif
                @if ($maisVendidos->isNotEmpty())
                    <button
                        class="inline-flex items-center gap-1 px-2 py-1 bg-yellow-500 border border-yellow-600 rounded-md font-semibold text-[10px] text-white uppercase tracking-widest shadow-sm hover:bg-yellow-400 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2 transition ease-in-out duration-150"
                        onclick="scrollToElement('secao_mais_vendidos')">
                        <i class='bx bxs-star'></i> Mais Vendidos
                    </button>
                @endif
                @foreach ($categorias as $categoria)
                    <button
                        class="inline-flex items-center px-2 py-1 bg-gray-200 border border-gray-300 rounded-md font-semibold text-[10px] text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                        onclick="scrollToElement('categoria_{{ $categoria->id }}')">
                        {{ $categoria->categoria_nome }}
                    </button>
                @endforeach
            </div>
        </div>
        <div class="h-[80%] overflow-y-auto p-2">

            {{-- Seção de Promoções --}}
            @if ($promocoes->isNotEmpty())
                @php $catsPromo = $promocoes->pluck('categoria')->unique('id')->sortBy('categoria_nome'); @endphp
                <div class="mb-6" id="secao_promocoes" x-data="{ catAtiva: 'todos' }">
                    <div class="flex items-center gap-2 mb-2">
                        <i class='bx bxs-purchase-tag text-orange-400 text-2xl'></i>
                        <h2 class="text-lg text-orange-400 font-bold uppercase">Promoções</h2>
                    </div>

                    {{-- Filtro por categoria --}}
                    @if ($catsPromo->count() > 1)
                        <div class="flex gap-1 flex-wrap mb-3">
                            <button type="button"
                                @click="catAtiva = 'todos'"
                                :class="catAtiva === 'todos' ? 'bg-orange-500 text-white border-orange-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">
                                Todos
                            </button>
                            @foreach ($catsPromo as $cat)
                                <button type="button"
                                    @click="catAtiva = '{{ $cat->id }}'"
                                    :class="catAtiva === '{{ $cat->id }}' ? 'bg-orange-500 text-white border-orange-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                    class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">
                                    {{ $cat->categoria_nome }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4">
                        @foreach ($promocoes as $produto)
                            <div class="relative snap-end"
                                x-show="catAtiva === 'todos' || catAtiva === '{{ $produto->categoria->id }}'">
                                <a href="{{ route('produto.show', ['produto' => $produto]) }}">
                                    <div class="w-full p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1 border border-orange-500/40 bg-orange-500/5">
                                        <div class="w-2/5 relative">
                                            <img src="{{ $produto->getImagemUrl() }}"
                                                alt="{{ $produto->produto_descricao }}"
                                                class="w-32 h-28 object-cover rounded-lg bg-white">
                                            <span class="absolute top-1 left-1 bg-orange-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                                                <i class='bx bxs-purchase-tag text-xs'></i> PROMO
                                            </span>
                                        </div>
                                        <div class="w-full h-28 flex flex-col justify-center space-y-1">
                                            <h2 class="text-gray-100 text-base uppercase">
                                                {{ $produto->categoria->categoria_nome }} {{ $produto->produto_descricao }}
                                            </h2>
                                            <div class="flex items-center">
                                                <span class="text-gray-400 text-xs">Codimentos: {{ $produto->produto_codimentacao }}</span>
                                            </div>
                                            <div class="flex flex-col">
                                                <span class="text-gray-400 text-xs line-through">DE: R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                <span class="text-green-400 text-lg font-bold">POR: R${{ str_replace('.', ',', $produto->produto_preco_promocional) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                                <hr class="h-px my-1 border-0 bg-orange-500/30">
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Seção Mais Vendidos --}}
            @if ($maisVendidos->isNotEmpty())
                @php $catsMV = $maisVendidos->pluck('categoria')->unique('id')->sortBy('categoria_nome'); @endphp
                <div class="mb-6" id="secao_mais_vendidos" x-data="{ catAtiva: 'todos' }">
                    <div class="flex items-center gap-2 mb-2">
                        <i class='bx bxs-star text-yellow-400 text-2xl'></i>
                        <h2 class="text-lg text-yellow-400 font-bold uppercase">Mais Vendidos</h2>
                    </div>

                    {{-- Filtro por categoria --}}
                    @if ($catsMV->count() > 1)
                        <div class="flex gap-1 flex-wrap mb-3">
                            <button type="button"
                                @click="catAtiva = 'todos'"
                                :class="catAtiva === 'todos' ? 'bg-yellow-500 text-white border-yellow-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">
                                Todos
                            </button>
                            @foreach ($catsMV as $cat)
                                <button type="button"
                                    @click="catAtiva = '{{ $cat->id }}'"
                                    :class="catAtiva === '{{ $cat->id }}' ? 'bg-yellow-500 text-white border-yellow-600' : 'bg-gray-700 text-gray-300 border-gray-600'"
                                    class="px-2 py-0.5 rounded text-[10px] font-semibold border transition">
                                    {{ $cat->categoria_nome }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4">
                        @foreach ($maisVendidos as $produto)
                            <div class="relative snap-end"
                                x-show="catAtiva === 'todos' || catAtiva === '{{ $produto->categoria->id }}'">
                                <a href="{{ route('produto.show', ['produto' => $produto]) }}">
                                    <div class="w-full p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1 border border-yellow-500/40 bg-yellow-500/5">
                                        <div class="w-2/5 relative">
                                            <img src="{{ $produto->getImagemUrl() }}"
                                                alt="{{ $produto->produto_descricao }}"
                                                class="w-32 h-28 object-cover rounded-lg bg-white">
                                            <span class="absolute top-1 left-1 bg-yellow-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                                                <i class='bx bxs-star text-xs'></i> + VENDIDO
                                            </span>
                                            @if ($produto->produto_preco_promocional > 0)
                                                <span class="absolute bottom-1 left-1 bg-orange-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                                                    <i class='bx bxs-purchase-tag text-xs'></i> PROMO
                                                </span>
                                            @endif
                                        </div>
                                        <div class="w-full h-28 flex flex-col justify-center space-y-1">
                                            <h2 class="text-gray-100 text-base uppercase">
                                                {{ $produto->categoria->categoria_nome }} {{ $produto->produto_descricao }}
                                            </h2>
                                            <div class="flex items-center">
                                                <span class="text-gray-400 text-xs">Codimentos: {{ $produto->produto_codimentacao }}</span>
                                            </div>
                                            <div class="flex flex-col">
                                                @if ($produto->produto_preco_promocional > 0)
                                                    <span class="text-gray-400 text-xs line-through">DE: R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                    <span class="text-green-400 text-lg font-bold">POR: R${{ str_replace('.', ',', $produto->produto_preco_promocional) }}</span>
                                                @else
                                                    <span class="text-white text-lg font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </a>
                                <hr class="h-px my-1 border-0 bg-yellow-500/30">
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Categorias --}}
            @foreach ($categorias as $categoria)
                <div class="mb-4" id="categoria_{{ $categoria->id }}">
                    <h2 class="text-lg text-white font-bold">{{ $categoria->categoria_nome }}</h2>
                    @if ($categoria->produtos->isEmpty())
                        <p class="text-gray-400">Não há produtos disponíveis nesta categoria.</p>
                    @else
                        <div class="grid grid-cols-1 gap-4 ">
                            @foreach ($categoria->produtos as $produto)
                                <div class="relative snap-end ">
                                    <a href="{{ route('produto.show', ['produto' => $produto]) }}">
                                        <div
                                            class="w-full p-2 rounded-lg flex items-start justify-between opacity-95 hover:opacity-100 gap-1">
                                            <div class="w-2/5 relative">
                                                <img src="{{ $produto->getImagemUrl() }}"
                                                    alt="{{ $produto->produto_descricao }}"
                                                    class="w-32 h-28 object-cover rounded-lg bg-white">
                                                @if ($produto->produto_preco_promocional > 0)
                                                    <span class="absolute top-1 left-1 bg-orange-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                                                        <i class='bx bxs-purchase-tag text-xs'></i> PROMO
                                                    </span>
                                                @endif
                                                @if (in_array($produto->id, $top10Ids ?? []))
                                                    <span class="absolute bottom-1 left-1 bg-yellow-500 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                                                        <i class='bx bxs-star text-xs'></i> +VENDIDO
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="w-full h-28 flex flex-col justify-center space-y-2">
                                                <h2 class="text-gray-100 text-lg uppercase">
                                                    @if (isset($produto->produto_referencia) && $produto->produto_referencia !== null)
                                                        {{ $produto->produto_descricao }} - <span>Ref.
                                                            {{ $produto->produto_referencia }}</span>
                                                    @else
                                                        {{ $produto->produto_descricao }}
                                                    @endif
                                                </h2>

                                                <div class="flex items-center">
                                                    <span class="text-gray-200 text-xs">Codimentos:
                                                        {{ $produto->produto_codimentacao }}</span>
                                                </div>

                                                <div class="flex flex-col">
                                                    @if ($produto->produto_preco_promocional > 0)
                                                        <span class="text-gray-400 text-xs line-through">DE: R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                        <span class="text-green-400 text-lg font-bold">POR: R${{ str_replace('.', ',', $produto->produto_preco_promocional) }}</span>
                                                    @else
                                                        <span class="text-white text-lg font-bold">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                    <hr class="h-px my-1 border-0 bg-gray-600">
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    <script>
        function scrollToElement(elementId) {
            var element = document.getElementById(elementId);

            if (element) {
                element.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                });
            }
        }
    </script>
</x-guest-layout>
