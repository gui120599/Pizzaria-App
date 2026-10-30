@props([
    'produto',
    'variante' => 'lista',
    'soTitulo' => false,
    'selo' => null,
    'ofertas' => null,
    'prioridade' => false,
])

{{--
    Card único do cardápio.
    - lista: texto à esquerda, foto 1:1 à direita (categorias).
    - vitrine: foto 4:3 em cima, para carrosséis (mais pedidos, relâmpago, promoções).
    soTitulo: dentro da própria categoria o título é só a descrição ("Calabresa");
    o nome que vai para o carrinho continua sendo o nome completo.
    selo: ['texto' => ..., 'icone' => ...] — só selos verdadeiros (ranking, top 10, relâmpago, oferta).
--}}
@php
    $titulo = $soTitulo ? $produto->titulo : $produto->nome;
@endphp

@if ($variante === 'vitrine')
    <article {{ $attributes->merge(['class' => 'w-[200px] shrink-0 snap-start overflow-hidden rounded-card border border-linha bg-forno-2 sm:w-[220px]']) }}>
        <div class="relative aspect-[4/3]">
            <x-cardapio.foto :produto="$produto" :largura="220" :prioridade="$prioridade" class="size-full" />
            @if ($selo)
                <span class="absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-ouro px-2 py-0.5 text-[11px] font-bold text-forno">
                    @isset($selo['icone'])<i class='bx {{ $selo['icone'] }}' aria-hidden="true"></i>@endisset{{ $selo['texto'] }}
                </span>
            @endif
            <x-cardapio.botao-adicionar :produto="$produto" class="absolute -bottom-5 right-1" />
        </div>
        <button type="button" @click="$store.cart.abrirDetalhe({{ $produto->id }})"
                class="block w-full px-3 pb-3 pt-4 text-left">
            <h3 class="line-clamp-2 min-h-10 text-[15px] font-semibold leading-snug first-letter:uppercase">{{ $titulo }}</h3>
            <x-cardapio.preco :produto="$produto" class="mt-1.5" />
        </button>
    </article>
@else
    <article data-busca="{{ $produto->busca }}"
             {{ $attributes->merge(['class' => 'card-lista']) }}>
        <button type="button" @click="$store.cart.abrirDetalhe({{ $produto->id }})"
                class="min-w-0 flex-1 text-left">
            @if ($selo)
                <span class="card-selo">
                    @isset($selo['icone'])<i class='bx {{ $selo['icone'] }}' aria-hidden="true"></i>@endisset{{ $selo['texto'] }}
                </span>
            @endif
            <h3 class="card-titulo">{{ $titulo }}</h3>
            @if ($produto->referencia)
                <span class="text-xs text-farinha">Ref. {{ $produto->referencia }}</span>
            @endif
            @if ($produto->descricao)
                <p class="card-descricao">{{ $produto->descricao }}</p>
            @endif
            @if ($ofertas && count($ofertas))
                <p class="mt-1 text-xs font-medium leading-snug text-ouro">
                    <i class='bx bxs-gift' aria-hidden="true"></i>
                    Leve também:
                    @foreach ($ofertas as $oferta)
                        {{ $oferta['nomeExibicao'] }} (+R$ {{ number_format($oferta['valorAdicional'], 2, ',', '.') }}){{ ! $loop->last ? ' ou ' : '' }}
                    @endforeach
                </p>
            @endif
            <x-cardapio.preco :produto="$produto" class="mt-2" />
        </button>
        <div class="relative size-24 shrink-0 md:size-28">
            <x-cardapio.foto :produto="$produto" :largura="112" :prioridade="$prioridade" class="size-full rounded-xl" />
            <x-cardapio.botao-adicionar :produto="$produto" class="absolute -bottom-3 -right-2" />
        </div>
    </article>
@endif
