@props(['produto'])

{{--
    "+" do card. Produto comum vira stepper (− qtd +); pizza de sabores mostra
    a quantidade e sempre abre a montagem, porque cada pizza é uma linha
    própria do carrinho. As chamadas ao store são as mesmas de sempre.
--}}
@php
    $nome = $produto->nome;
    $foto = $produto->temFoto() ? $produto->foto(48) : '';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-1']) }}>
    @if ($produto->permiteSabores)
        <span x-show="$store.cart.qty({{ $produto->id }}) > 0" x-cloak
              class="num contador-card"
              x-text="$store.cart.qty({{ $produto->id }})"></span>
        <button type="button"
                @click.stop="$store.cart.abrirSabores({{ $produto->categoriaId }}, @js($produto->categoriaNome), {{ $produto->maxSabores }}, {{ $produto->id }})"
                aria-label="Montar {{ $nome }}"
                class="botao-mais">
            <i class='bx bx-plus' aria-hidden="true"></i>
        </button>
    @else
        <div x-show="$store.cart.qty({{ $produto->id }}) > 0" x-cloak
             class="stepper-card">
            <button type="button" @click.stop="$store.cart.decrement({{ $produto->id }})"
                    aria-label="Tirar um {{ $nome }}"
                    class="stepper-card-menos">−</button>
            <span class="num stepper-card-qtd" x-text="$store.cart.qty({{ $produto->id }})"></span>
        </div>
        <button type="button"
                @click.stop="$store.cart.add({{ $produto->id }}, @js($nome), {{ $produto->preco }}, {{ $produto->precoVenda }}, @js($foto))"
                aria-label="Adicionar {{ $nome }}"
                class="botao-mais">
            <i class='bx bx-plus' aria-hidden="true"></i>
        </button>
    @endif
</div>
