{{--
    Seções de Promoções e Mais Vendidos para as views de pedido.
    Variáveis esperadas: $promocoes (Collection), $maisVendidos (Collection)
--}}

{{-- Seção Promoções --}}
@if (isset($promocoes) && $promocoes->isNotEmpty())
    @php $catsPromo = $promocoes->pluck('categoria')->unique('id')->sortBy('categoria_nome'); @endphp
    <div class="mb-4" id="secao_promocoes">
        <h2 class="text-base font-bold flex items-center gap-1 text-orange-600">
            <i class='bx bxs-purchase-tag'></i> Promoções
        </h2>

        {{-- Filtro por categoria --}}
        @if ($catsPromo->count() > 1)
            <div class="flex gap-1 flex-wrap mb-2" id="filtro-promocoes">
                <button type="button"
                    class="filtro-cat-promo active px-2 py-0.5 rounded text-xs font-semibold bg-orange-500 text-white border border-orange-600"
                    data-cat-id="todos">
                    Todos
                </button>
                @foreach ($catsPromo as $cat)
                    <button type="button"
                        class="filtro-cat-promo px-2 py-0.5 rounded text-xs font-semibold bg-gray-200 text-gray-700 border border-gray-300 hover:bg-orange-100"
                        data-cat-id="{{ $cat->id }}">
                        {{ $cat->categoria_nome }}
                    </button>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-2 pe-1" id="grid-promocoes">
            @foreach ($promocoes as $produto)
                <div class="relative snap-end card-promo" data-cat-id="{{ $produto->categoria->id }}">
                    <div class="produto cursor-pointer hover:shadow-lg"
                        data-produto_id="{{ $produto->id }}"
                        data-produto_valor="{{ $produto->produto_preco_venda }}"
                        data-produto_preco_promocional="{{ $produto->produto_preco_promocional }}">
                        <div class="w-full flex flex-col bg-orange-50 border border-orange-300 p-2 rounded-lg opacity-95 hover:opacity-100 gap-1 justify-stretch max-h-40">
                            <div class="max-h-24 flex flex-col justify-between">
                                <span class="self-start text-[10px] bg-orange-500 text-white px-1 rounded font-bold flex items-center gap-0.5">
                                    <i class='bx bxs-purchase-tag text-xs'></i> PROMO
                                </span>
                                <p class="text-gray-900 font-bold text-sm md:text-xs uppercase produto_descricao">
                                    {{ $produto->categoria->categoria_nome }}
                                    {{ $produto->produto_descricao }}
                                </p>
                                <span class="text-gray-400 line-through text-xs leading-none">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                <span class="text-green-600 text-lg font-bold leading-tight">R${{ str_replace('.', ',', $produto->produto_preco_promocional) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        (function () {
            document.querySelectorAll('#filtro-promocoes .filtro-cat-promo').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var catId = this.dataset.catId;

                    document.querySelectorAll('#filtro-promocoes .filtro-cat-promo').forEach(function (b) {
                        b.classList.remove('active', 'bg-orange-500', 'text-white', 'border-orange-600');
                        b.classList.add('bg-gray-200', 'text-gray-700', 'border-gray-300');
                    });
                    this.classList.add('active', 'bg-orange-500', 'text-white', 'border-orange-600');
                    this.classList.remove('bg-gray-200', 'text-gray-700', 'border-gray-300');

                    document.querySelectorAll('#grid-promocoes .card-promo').forEach(function (card) {
                        card.style.display = (catId === 'todos' || card.dataset.catId === catId) ? '' : 'none';
                    });
                });
            });
        })();
    </script>
@endif

{{-- Seção Mais Vendidos --}}
@if (isset($maisVendidos) && $maisVendidos->isNotEmpty())
    @php
        $categoriasMaisVendidos = $maisVendidos->pluck('categoria')->unique('id')->sortBy('categoria_nome');
    @endphp
    <div class="mb-4" id="secao_mais_vendidos">
        <h2 class="text-base font-bold flex items-center gap-1 text-yellow-600">
            <i class='bx bxs-star'></i> Mais Vendidos
        </h2>

        {{-- Filtro por categoria --}}
        @if ($categoriasMaisVendidos->count() > 1)
            <div class="flex gap-1 flex-wrap mb-2" id="filtro-mais-vendidos">
                <button type="button"
                    class="filtro-cat-mv active px-2 py-0.5 rounded text-xs font-semibold bg-yellow-500 text-white border border-yellow-600"
                    data-cat-id="todos">
                    Todos
                </button>
                @foreach ($categoriasMaisVendidos as $cat)
                    <button type="button"
                        class="filtro-cat-mv px-2 py-0.5 rounded text-xs font-semibold bg-gray-200 text-gray-700 border border-gray-300 hover:bg-yellow-100"
                        data-cat-id="{{ $cat->id }}">
                        {{ $cat->categoria_nome }}
                    </button>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-2 pe-1" id="grid-mais-vendidos">
            @foreach ($maisVendidos as $produto)
                <div class="relative snap-end card-mais-vendido" data-cat-id="{{ $produto->categoria->id }}">
                    <div class="produto cursor-pointer hover:shadow-lg"
                        data-produto_id="{{ $produto->id }}"
                        data-produto_valor="{{ $produto->produto_preco_venda }}"
                        data-produto_preco_promocional="{{ $produto->produto_preco_promocional ?? 0 }}">
                        <div class="w-full flex flex-col bg-yellow-50 border border-yellow-300 p-2 rounded-lg opacity-95 hover:opacity-100 gap-1 justify-stretch max-h-40">
                            <div class="max-h-24 flex flex-col justify-between">
                                <span class="self-start text-[10px] bg-yellow-500 text-white px-1 rounded font-bold flex items-center gap-0.5">
                                    <i class='bx bxs-star text-xs'></i> + VENDIDO
                                </span>
                                <p class="text-gray-900 font-bold text-sm md:text-xs uppercase produto_descricao">
                                    {{ $produto->categoria->categoria_nome }}
                                    {{ $produto->produto_descricao }}
                                </p>
                                @if ($produto->produto_preco_promocional > 0)
                                    <span class="text-gray-400 line-through text-xs leading-none">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                    <span class="text-green-600 text-lg font-bold leading-tight">R${{ str_replace('.', ',', $produto->produto_preco_promocional) }}</span>
                                @else
                                    <span class="text-green-500 text-xl">R${{ str_replace('.', ',', $produto->produto_preco_venda) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        (function () {
            document.querySelectorAll('#filtro-mais-vendidos .filtro-cat-mv').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var catId = this.dataset.catId;

                    document.querySelectorAll('#filtro-mais-vendidos .filtro-cat-mv').forEach(function (b) {
                        b.classList.remove('active', 'bg-yellow-500', 'text-white', 'border-yellow-600');
                        b.classList.add('bg-gray-200', 'text-gray-700', 'border-gray-300');
                    });
                    this.classList.add('active', 'bg-yellow-500', 'text-white', 'border-yellow-600');
                    this.classList.remove('bg-gray-200', 'text-gray-700', 'border-gray-300');

                    document.querySelectorAll('#grid-mais-vendidos .card-mais-vendido').forEach(function (card) {
                        if (catId === 'todos' || card.dataset.catId === catId) {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            });
        })();
    </script>
@endif
