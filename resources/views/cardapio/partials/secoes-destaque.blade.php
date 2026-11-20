{{--
    Destaques acima das categorias — todos vêm de dados reais e só aparecem
    quando existem: campanha "leve outro produto", promoção relâmpago (com o
    saldo real), promoções de produto e os mais pedidos (ranking pela
    quantidade vendida).
--}}

{{-- Promoção Combo: banner (quando cadastrado), a regra e os itens participantes. --}}
@foreach ($promocoesCombo ?? [] as $combo)
    <section id="{{ $loop->first ? 'secao_combo' : 'secao_combo_'.$combo['promocaoId'] }}"
             data-secao data-ocultar-na-busca x-data="trilhoRolavel" class="px-4 pt-6 lg:px-0">
        <div class="overflow-hidden rounded-card border border-ouro/30 bg-[linear-gradient(135deg,rgb(242_179_61/.10),transparent_60%)]">
            {{-- Celular: arte quadrada (1080²); computador: faixa 21:8 (1680 × 640). Sem uma, vale a outra. --}}
            @if ($combo['imagem'] || $combo['imagem_mobile'])
                <picture>
                    @if ($combo['imagem'] && $combo['imagem_mobile'])
                        <source media="(min-width: 1024px)" srcset="{{ $combo['imagem'] }}" width="1680" height="640">
                    @endif
                    <img src="{{ $combo['imagem_mobile'] ?? $combo['imagem'] }}" alt="{{ $combo['nome'] }}"
                         width="{{ $combo['imagem_mobile'] ? 1080 : 1680 }}" height="{{ $combo['imagem_mobile'] ? 1080 : 640 }}"
                         @if (! $loop->first) loading="lazy" @endif
                         class="{{ $combo['imagem_mobile'] ? 'aspect-square' : 'aspect-[21/8]' }} w-full bg-forno-3 object-cover lg:aspect-[21/8]">
                </picture>
            @endif
            <div class="flex items-end gap-3 px-4 pt-4">
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-[.12em] text-ouro">
                        <i class='bx bxs-offer text-base' aria-hidden="true"></i> Promoção combo
                    </p>
                    <h2 class="mt-0.5 font-display text-2xl font-bold tracking-tight lg:text-[26px]">{{ $combo['nome'] }}</h2>
                    <p class="mt-0.5 text-[14px] text-creme">
                        Leve <b>{{ $combo['composicao'] }}</b> por <b class="num text-ouro">R$ {{ number_format($combo['preco'], 2, ',', '.') }}</b>
                        <span class="text-[12.5px] text-farinha">· bordas e adicionais à parte</span>
                    </p>
                    @if ($combo['descricao'])
                        <p class="mt-1 text-[13.5px] text-farinha">{{ $combo['descricao'] }}</p>
                    @endif
                </div>
                {{-- Quantidade limitada: quantos combos ainda saem (a esgotada nem chega aqui). --}}
                @if (($combo['restam'] ?? null) !== null)
                    <span class="ml-auto flex shrink-0 flex-col items-center self-start rounded-xl bg-ouro px-2.5 py-1 text-forno">
                        <span class="num text-lg font-extrabold leading-none">{{ number_format($combo['restam'], 0, ',', '.') }}</span>
                        <span class="text-[10px] font-bold uppercase tracking-wide">restam</span>
                    </span>
                @endif
                {{-- Setas do trilho (só no desktop, como em x-cardapio.carrossel; no celular é arrastar). --}}
                <div x-show="podeVoltar || podeAvancar" @class(['hidden shrink-0 gap-2 lg:flex', 'ml-auto' => ($combo['restam'] ?? null) === null])>
                    <button type="button" @click="rolar(-1)" :disabled="!podeVoltar"
                            class="grid size-9 place-items-center rounded-full border border-linha text-xl text-farinha hover:text-creme disabled:opacity-30" aria-label="Anteriores">
                        <i class='bx bx-chevron-left' aria-hidden="true"></i>
                    </button>
                    <button type="button" @click="rolar(1)" :disabled="!podeAvancar"
                            class="grid size-9 place-items-center rounded-full border border-linha text-xl text-farinha hover:text-creme disabled:opacity-30" aria-label="Próximos">
                        <i class='bx bx-chevron-right' aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div x-ref="trilho" @scroll.passive="medir()" class="rolagem-x mt-3 flex snap-x gap-3 scroll-px-4 px-4 pb-4">
                @foreach (array_slice($combo['produtos'], 0, 12) as $produtoId)
                    @isset($produtosTela[$produtoId])
                        <x-cardapio.produto-card variante="vitrine" :produto="$produtosTela[$produtoId]" :selo="['texto' => 'COMBO', 'icone' => 'bxs-offer']" />
                    @endisset
                @endforeach
            </div>
        </div>
    </section>
@endforeach

@foreach ($campanhasAdicionaisAtivas as $campanha)
    <section id="{{ $loop->first ? 'secao_promocao_adicional' : 'secao_promocao_adicional_'.$loop->index }}"
             data-secao data-ocultar-na-busca class="px-4 pt-6 lg:px-0">
        <div class="rounded-card border border-ouro/30 bg-[linear-gradient(135deg,rgb(242_179_61/.10),transparent_60%)] p-4">
            <div class="flex items-start gap-2">
                <h2 class="flex items-center gap-2 font-display text-2xl font-bold tracking-tight">
                    <i class='bx bxs-gift text-[22px] text-ouro' aria-hidden="true"></i>{{ $campanha['nome'] }}
                </h2>
                @if (($campanha['saldo'] ?? null) !== null)
                    <span class="ml-auto flex shrink-0 flex-col items-center rounded-xl bg-ouro px-2.5 py-1 text-forno lg:ml-4">
                        <span class="num text-lg font-extrabold leading-none">{{ number_format($campanha['saldo'], 0, ',', '.') }}</span>
                        <span class="text-[10px] font-bold uppercase tracking-wide">restam</span>
                    </span>
                @endif
            </div>
            @if ($campanha['descricao'])
                <p class="mt-0.5 text-[13.5px] text-farinha">{{ $campanha['descricao'] }}</p>
            @endif
            <div class="mt-2 grid md:grid-cols-2 md:gap-3">
                @foreach ($campanha['produtos'] as $item)
                    <x-cardapio.produto-card :produto="$produtosTela[$item['produto']->id]" :ofertas="$item['ofertas']"
                                             :selo="['texto' => 'Oferta especial', 'icone' => 'bxs-gift']" class="last:border-b-0" />
                @endforeach
            </div>
        </div>
    </section>
@endforeach

@foreach ($promocoesRelampago as $promo)
    <x-cardapio.carrossel :id="$loop->first ? 'secao_relampago' : 'secao_relampago_'.$promo['id']"
                          :titulo="$promo['nome']" :subtitulo="$promo['descricao']" icone="bxs-bolt raio-uma-vez">
        @if ($promo['exibe_contador'])
            <x-slot:acoes>
                <span class="ml-auto flex shrink-0 flex-col items-center rounded-xl bg-ouro px-2.5 py-1 text-forno lg:ml-4">
                    <span class="num text-lg font-extrabold leading-none">{{ number_format($promo['saldo'], 0, ',', '.') }}</span>
                    <span class="text-[10px] font-bold uppercase tracking-wide">restam</span>
                </span>
            </x-slot:acoes>
        @endif
        @foreach ($promo['produtos'] as $produto)
            <x-cardapio.produto-card variante="vitrine" :produto="$produtosTela[$produto->id]"
                                     :selo="['texto' => 'RELÂMPAGO', 'icone' => 'bxs-bolt']" :prioridade="$loop->parent->first && $loop->index < 2" />
        @endforeach
    </x-cardapio.carrossel>
@endforeach

@if ($promocoes->isNotEmpty())
    <x-cardapio.carrossel id="secao_promocoes" titulo="Promoções" subtitulo="Com preço especial" icone="bxs-purchase-tag">
        @foreach ($promocoes as $produto)
            <x-cardapio.produto-card variante="vitrine" :produto="$produtosTela[$produto->id]" />
        @endforeach
    </x-cardapio.carrossel>
@endif

@if ($maisVendidos->isNotEmpty())
    <x-cardapio.carrossel id="secao_mais_vendidos" titulo="Os mais pedidos" subtitulo="Os favoritos de quem pede no Empório">
        @foreach ($maisVendidos as $produto)
            <x-cardapio.produto-card variante="vitrine" :produto="$produtosTela[$produto->id]"
                                     :selo="['texto' => 'nº '.$loop->iteration]" :prioridade="$loop->index < 2" />
        @endforeach
    </x-cardapio.carrossel>
@endif
