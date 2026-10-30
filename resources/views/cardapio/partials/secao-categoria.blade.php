{{--
    Seção de uma categoria de topo. Filhas (tamanhos da pizza, tipos de
    bebida) viram subtítulos com filtro em pílulas fixas — mesmo estado
    catFilhaAtiva de antes. id="categoria_{id}" é âncora das abas e contrato
    de teste (a mãe sempre antes das filhas).
--}}
@php
    $top10 = ['texto' => 'Mais pedido', 'icone' => 'bxs-star'];
@endphp

<section id="categoria_{{ $categoria->id }}" data-secao data-secao-categoria
         x-data="{ catFilhaAtiva: 'todos' }" @cardapio-busca.window="catFilhaAtiva = 'todos'"
         class="px-4 pt-8 lg:px-0">
    <h2 class="font-display text-[26px] font-bold tracking-tight">{{ $categoria->categoria_nome }}</h2>

    @if ($categoria->filhas->count() > 1)
        <div class="sticky z-20 -mx-4 bg-forno/95 px-4 py-2.5 backdrop-blur-md lg:mx-0 lg:px-0"
             style="top: calc(var(--altura-topo, 56px) + var(--altura-abas, 57px))"
             data-ocultar-na-busca>
            <x-cardapio.trilho rotulo="subcategorias">
                <button type="button" @click="catFilhaAtiva = 'todos'; alinharSecao($el)" :aria-pressed="catFilhaAtiva === 'todos'"
                        :class="catFilhaAtiva === 'todos' ? 'border-creme bg-creme text-forno' : 'border-linha bg-forno-2 text-farinha hover:text-creme'"
                        class="h-9 shrink-0 whitespace-nowrap rounded-full border px-4 text-[13.5px] font-semibold transition-colors">Todos</button>
                @foreach ($categoria->filhas as $filha)
                    <button type="button" @click="catFilhaAtiva = '{{ $filha->id }}'; alinharSecao($el)" :aria-pressed="catFilhaAtiva === '{{ $filha->id }}'"
                            :class="catFilhaAtiva === '{{ $filha->id }}' ? 'border-creme bg-creme text-forno' : 'border-linha bg-forno-2 text-farinha hover:text-creme'"
                            class="h-9 shrink-0 whitespace-nowrap rounded-full border px-4 text-[13.5px] font-semibold transition-colors">{{ $filha->categoria_nome }}</button>
                @endforeach
            </x-cardapio.trilho>
        </div>
    @endif

    @if ($categoria->produtos->isEmpty() && $categoria->filhas->isEmpty())
        <p class="mt-3 text-farinha">Não há produtos disponíveis nesta categoria.</p>
    @endif

    @if ($categoria->produtos->isNotEmpty())
        <div data-grupo x-show="catFilhaAtiva === 'todos'" class="mt-2 grid md:grid-cols-2 md:gap-3">
            @foreach ($categoria->produtos as $produto)
                <x-cardapio.produto-card :produto="$produtosTela[$produto->id]" so-titulo
                                         :selo="in_array($produto->id, $top10Ids) ? $top10 : null" />
            @endforeach
        </div>
    @endif

    @foreach ($categoria->filhas as $filha)
        <div data-grupo x-show="catFilhaAtiva === 'todos' || catFilhaAtiva === '{{ $filha->id }}'">
            <h3 id="categoria_{{ $filha->id }}" class="mt-6 text-[13px] font-bold uppercase tracking-[.14em] text-brasa-viva">{{ $filha->categoria_nome }}</h3>
            <div class="mt-1 grid md:mt-3 md:grid-cols-2 md:gap-3">
                @foreach ($filha->produtos as $produto)
                    <x-cardapio.produto-card :produto="$produtosTela[$produto->id]" so-titulo
                                             :selo="in_array($produto->id, $top10Ids) ? $top10 : null" />
                @endforeach
            </div>
        </div>
    @endforeach
</section>
