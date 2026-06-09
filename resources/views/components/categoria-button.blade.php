@props(['categoria', 'onclick' => null, 'dark' => true])

@php
    $primeiroProdutoComFoto = $categoria->produtos->first(fn($p) => $p->produto_foto);
    $imagemUrl = $primeiroProdutoComFoto?->getImagemUrl() ?? asset('img/logo Pizzaria Branco Colorido.png');

    $borderClass   = $dark ? 'border-gray-600 group-hover:border-gray-400' : 'border-gray-300 group-hover:border-gray-500';
    $textClass     = $dark ? 'text-gray-300' : 'text-gray-600';
@endphp

<button
    {{ $attributes->merge(['class' => 'flex flex-col items-center gap-1 shrink-0 w-16 focus:outline-none group']) }}
    @if($onclick) onclick="{{ $onclick }}" @endif
>
    <div class="w-14 h-14 rounded-xl overflow-hidden border-2 {{ $borderClass }} transition-colors duration-150 shadow-sm">
        <img
            src="{{ $imagemUrl }}"
            alt="{{ $categoria->categoria_nome }}"
            class="w-full h-full object-cover"
        >
    </div>
    <span class="text-[9px] {{ $textClass }} font-semibold uppercase tracking-wide leading-tight text-center line-clamp-2 w-full">
        {{ $categoria->categoria_nome }}
    </span>
</button>
