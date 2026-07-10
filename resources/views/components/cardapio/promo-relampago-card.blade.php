@props(['produto'])

@php
    $nomeExibicao  = $produto->nomeExibicao();
    $preco         = $produto->precoResolvido();
    $precoCarrinho = $preco->precoFinal();
    // A política de sabores vem da promoção, não da categoria: promoção "só
    // inteira" não pode oferecer meia a meia mesmo em categoria que permite.
    $permiteSabores = $produto->permiteSaboresCardapio();
@endphp

<div class="relative">
    <a href="{{ route('produto.show', ['produto' => $produto]) }}">
        <div class="w-full p-2 rounded-lg flex items-start justify-between gap-1 border border-red-500/40 bg-gradient-to-br from-red-500/10 to-orange-500/5">
            <div class="w-2/5 relative">
                <img src="{{ $produto->getImagemUrl() }}" alt="{{ $produto->produto_descricao }}"
                     class="w-32 h-28 object-cover rounded-lg bg-white">
                <span class="absolute top-1 left-1 bg-red-600 text-white text-[10px] font-bold px-1 py-0.5 rounded flex items-center gap-0.5">
                    <i class='bx bxs-bolt text-xs'></i> RELÂMPAGO
                </span>
            </div>
            <div class="w-full h-28 flex flex-col justify-center space-y-1">
                <h2 class="text-gray-100 text-base uppercase">{{ $nomeExibicao }}</h2>
                <span class="text-gray-400 text-xs">Codimentos: {{ $produto->produto_codimentacao }}</span>
                <div class="flex flex-col">
                    @if ($preco->descontoUnitario > 0)
                        <span class="text-gray-400 text-xs line-through">DE: R${{ number_format($preco->valorUnitario, 2, ',', '.') }}</span>
                    @endif
                    <span class="text-green-400 text-lg font-bold">POR: R${{ number_format($precoCarrinho, 2, ',', '.') }}</span>
                </div>
            </div>
        </div>
    </a>
    <div class="absolute bottom-3 right-2 flex items-center gap-1 z-10">
        @if ($permiteSabores)
            <div x-show="$store.cart.qty({{ $produto->id }}) > 0"
                 class="flex items-center gap-1 bg-black/70 rounded-full px-1.5 py-0.5 text-white text-xs font-bold" style="display:none">
                <i class='bx bx-bowl-hot text-xs'></i>
                <span x-text="$store.cart.qty({{ $produto->id }})"></span>
            </div>
            <button @click.stop="$store.cart.abrirSabores({{ $produto->categoria->id }}, @js($produto->categoria->categoria_nome), {{ $produto->maxSaboresCardapio() }}, {{ $produto->id }})"
                    class="w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow-lg transition-all">+</button>
        @else
            <div x-show="$store.cart.qty({{ $produto->id }}) > 0"
                 class="flex items-center gap-1 bg-black/70 rounded-full px-1.5 py-0.5" style="display:none">
                <button @click.stop="$store.cart.decrement({{ $produto->id }})"
                        class="w-5 h-5 flex items-center justify-center text-white font-bold hover:text-red-400 transition text-base leading-none">−</button>
                <span class="text-white text-xs font-bold min-w-[0.75rem] text-center" x-text="$store.cart.qty({{ $produto->id }})"></span>
            </div>
            <button @click.stop="$store.cart.add({{ $produto->id }}, @js($nomeExibicao), {{ $precoCarrinho }}, {{ $produto->produto_preco_venda }}, @js($produto->getImagemUrl()))"
                    class="w-8 h-8 rounded-full bg-green-500 hover:bg-green-400 active:scale-90 text-white text-xl font-bold flex items-center justify-center shadow-lg transition-all">+</button>
        @endif
    </div>
</div>
