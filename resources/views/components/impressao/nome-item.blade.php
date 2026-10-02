{{--
    Nome do item nas impressões: categoria como badge (borda preta — sai
    legível em impressora térmica, sem depender de cor) e, para pizza de
    vários sabores, um sabor por linha ("MEIA CALABRESA" / "MEIA MUSSARELA").
--}}
@props(['item', 'categoria' => true])

@php
    $nomeCategoria = $categoria ? $item->produto?->categoria?->categoria_nome : null;
@endphp

@if ($nomeCategoria)
    <span class="inline-block rounded border-2 border-black px-1.5 py-0.5 text-sm font-bold uppercase leading-tight">{{ $nomeCategoria }}</span>
@endif

@if ($item->ehMultiSabor())
    @foreach ($item->linhasSabores() as $linha)
        <span class="block">{{ $linha }}</span>
    @endforeach
@else
    {{ $item->nomeProduto() }}
@endif
