{{--
    Nome de um item (de pedido ou venda) nas telas: categoria como badge e,
    para pizza de vários sabores, um sabor por linha com a fração por
    extenso ("MEIA CALABRESA" / "MEIA MUSSARELA"). Item comum: o produto.
    A impressão tem a versão própria em impressao/nome-item.
--}}
@props(['item', 'categoria' => true])

@php
    $nomeCategoria = $categoria ? $item->produto?->categoria?->categoria_nome : null;
@endphp

<span {{ $attributes }}>
    @if ($nomeCategoria)
        <span class="inline-block rounded border border-teal-200 bg-teal-50 px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-teal-700 dark:border-teal-500/20 dark:bg-teal-500/10 dark:text-teal-400">{{ $nomeCategoria }}</span>
    @endif

    @if ($item->ehMultiSabor())
        @foreach ($item->linhasSabores() as $linha)
            <span class="block">{{ $linha }}</span>
        @endforeach
    @else
        {{ $item->nomeProduto() }}
    @endif
</span>
