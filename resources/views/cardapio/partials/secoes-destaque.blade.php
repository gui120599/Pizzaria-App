{{--
    Destaques acima das categorias — todos vêm de dados reais e só aparecem
    quando existem: campanha "leve outro produto", promoção relâmpago (com o
    saldo real), promoções de produto e os mais pedidos (ranking pela
    quantidade vendida).
--}}

@foreach ($campanhasAdicionaisAtivas as $campanha)
    <section id="{{ $loop->first ? 'secao_promocao_adicional' : 'secao_promocao_adicional_'.$loop->index }}"
             data-secao data-ocultar-na-busca class="px-4 pt-6 lg:px-0">
        <div class="rounded-card border border-ouro/30 bg-[linear-gradient(135deg,rgb(242_179_61/.10),transparent_60%)] p-4">
            <h2 class="flex items-center gap-2 font-display text-2xl font-bold tracking-tight">
                <i class='bx bxs-gift text-[22px] text-ouro' aria-hidden="true"></i>{{ $campanha['nome'] }}
            </h2>
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
