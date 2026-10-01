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
    <span class="inline-block rounded border border-black px-1 text-[9px] font-bold uppercase leading-tight">{{ $nomeCategoria }}</span>
@endif

@if ($item->ehMultiSabor())
    @foreach ($item->linhasSabores() as $linha)
        <span class="block">{{ $linha }}</span>
    @endforeach
@else
    {{ $item->nomeProduto() }}
@endif
