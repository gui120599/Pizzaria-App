{{--
    Abas fixas abaixo do cabeçalho: destaques que existirem agora + categorias
    de topo. A aba da seção visível fica marcada (scrollspy em cardapioPagina).
    No celular a busca abre aqui; no desktop ela está no cabeçalho.
--}}
@php
    $atalhos = collect()
        ->when($promocoesRelampago->isNotEmpty(), fn ($c) => $c->push(['alvo' => 'secao_relampago', 'nome' => 'Relâmpago', 'icone' => 'bxs-bolt']))
        ->when($campanhasAdicionaisAtivas->isNotEmpty(), fn ($c) => $c->push(['alvo' => 'secao_promocao_adicional', 'nome' => 'Ofertas', 'icone' => 'bxs-gift']))
        ->when($promocoes->isNotEmpty(), fn ($c) => $c->push(['alvo' => 'secao_promocoes', 'nome' => 'Promoções', 'icone' => 'bxs-purchase-tag']))
        ->when($maisVendidos->isNotEmpty(), fn ($c) => $c->push(['alvo' => 'secao_mais_vendidos', 'nome' => 'Mais pedidos', 'icone' => 'bxs-star']))
        ->merge($categorias->map(fn ($c) => ['alvo' => 'categoria_'.$c->id, 'nome' => $c->categoria_nome, 'icone' => null]));
@endphp

<nav id="abas-fixas" aria-label="Seções do cardápio"
     class="sticky z-30 border-b border-linha bg-forno/95 backdrop-blur-md"
     style="top: var(--altura-topo, 56px)">
    <div class="mx-auto flex w-full max-w-[1232px] items-center gap-2 px-4 py-2 lg:px-6">
        <x-cardapio.trilho x-show="!buscaAberta" id="trilho-abas" rotulo="seções" class="flex-1">
            @foreach ($atalhos as $atalho)
                <button type="button" data-aba="{{ $atalho['alvo'] }}" @click="irPara(@js($atalho['alvo']))"
                        :aria-current="secaoAtiva === @js($atalho['alvo']) ? 'true' : 'false'"
                        :class="secaoAtiva === @js($atalho['alvo']) ? 'bg-forno-3 text-creme after:bg-brasa' : 'text-farinha hover:text-creme after:bg-transparent'"
                        class="relative flex h-10 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-3.5 text-sm font-semibold transition-colors after:absolute after:inset-x-4 after:bottom-1 after:h-0.5 after:rounded-full">
                    @if ($atalho['icone'])
                        <i class='bx {{ $atalho['icone'] }} text-ouro' aria-hidden="true"></i>
                    @endif
                    {{ $atalho['nome'] }}
                </button>
            @endforeach
        </x-cardapio.trilho>

        <label x-show="buscaAberta" x-cloak class="flex h-11 flex-1 items-center gap-2 rounded-xl border border-brasa-viva bg-forno-2 px-3 text-farinha lg:hidden">
            <i class='bx bx-search text-xl' aria-hidden="true"></i>
            <span class="sr-only">Buscar no cardápio</span>
            <input type="search" x-ref="campoBusca" x-model="busca" x-effect="buscaAberta && $nextTick(() => $el.focus())" @input.debounce.150ms="filtrar()" @keydown.escape="limparBusca()"
                   placeholder="Pizza, pastel, ingrediente…"
                   class="w-full border-0 bg-transparent p-0 text-base text-creme placeholder:text-farinha focus:ring-0">
        </label>

        <button type="button" @click="alternarBusca()" class="grid size-10 shrink-0 place-items-center rounded-full border border-linha bg-forno-2 text-xl lg:hidden"
                :aria-label="buscaAberta ? 'Fechar busca' : 'Buscar no cardápio'">
            <i class='bx' :class="buscaAberta ? 'bx-x' : 'bx-search'" aria-hidden="true"></i>
        </button>
    </div>
</nav>
