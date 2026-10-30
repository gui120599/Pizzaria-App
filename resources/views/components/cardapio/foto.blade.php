@props(['produto', 'largura', 'prioridade' => false])

{{--
    Foto do produto em proporção fixa (o contêiner define o tamanho): miniatura
    WebP adequada à largura exibida, carregamento sob demanda e um bloco com o
    monograma quando o produto não tem foto — sem salto de layout.
--}}
@if ($produto->temFoto())
    <img src="{{ $produto->foto($largura) }}"
         alt="{{ $produto->nome }}"
         width="{{ $largura }}" height="{{ $largura }}"
         @if ($prioridade) fetchpriority="high" @else loading="lazy" @endif
         decoding="async"
         {{ $attributes->merge(['class' => 'bg-forno-3 object-cover']) }}>
@else
    <div role="img" aria-label="{{ $produto->nome }} (sem foto)"
         {{ $attributes->merge(['class' => 'grid place-items-center bg-forno-3']) }}>
        <span class="font-display text-3xl font-bold text-linha" aria-hidden="true">E</span>
    </div>
@endif
