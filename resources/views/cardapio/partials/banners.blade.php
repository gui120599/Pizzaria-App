{{--
    Carrossel do topo (Cardápio › Banners do cardápio). As imagens vêm do
    servidor já filtradas pela vigência; o Alpine (carrosselBanners) só troca
    sozinho, mostra setas/pontos e para quando o cliente mexe.

    Celular: as artes quadradas só valem quando TODOS os banners no ar têm
    imagem de celular. Misturar quadrado e faixa larga deixaria o carrossel
    com buracos; nesse caso todos usam a faixa larga (21:8).
--}}
@php
    $quadradoNoCelular = collect($banners)->every(fn (array $banner) => filled($banner['imagem_mobile']));
@endphp

<section x-data="carrosselBanners({{ count($banners) }})" class="mx-auto w-full max-w-[1232px] px-4 pt-3 lg:px-6 lg:pt-5"
         aria-roledescription="carrossel" aria-label="Destaques">
    <div class="relative">
        <div x-ref="trilho" @scroll.passive="aoRolar()" @pointerdown="pausar()" @mouseenter="pausar()" @mouseleave="retomar()"
             class="rolagem-x flex snap-x snap-mandatory overflow-x-auto rounded-card">
            @foreach ($banners as $banner)
                @php
                    $destino = $banner['destino'];
                    $tag = match ($destino['tipo'] ?? null) {
                        'link' => 'a',
                        'categoria', 'produto' => 'button',
                        default => 'div',
                    };
                @endphp
                <{{ $tag }}
                    @if ($tag === 'a') href="{{ $destino['alvo'] }}" target="_blank" rel="noopener noreferrer" @endif
                    @if ($tag === 'button') type="button" @endif
                    @if (($destino['tipo'] ?? null) === 'categoria') @click="irPara('categoria_{{ $destino['alvo'] }}')" @endif
                    @if (($destino['tipo'] ?? null) === 'produto') @click="$store.cart.abrirDetalhe({{ $destino['alvo'] }})" @endif
                    class="block w-full shrink-0 snap-center overflow-hidden rounded-card bg-forno-3 text-left"
                    aria-roledescription="slide" aria-label="{{ $loop->iteration }} de {{ $loop->count }}: {{ $banner['titulo'] }}">
                    <picture>
                        @if ($banner['imagem_mobile'] && $quadradoNoCelular)
                            <source media="(min-width: 1024px)" srcset="{{ $banner['imagem'] }}" width="1680" height="640">
                        @endif
                        <img src="{{ $quadradoNoCelular ? $banner['imagem_mobile'] : $banner['imagem'] }}"
                             alt="{{ $banner['titulo'] }}"
                             width="{{ $quadradoNoCelular ? 1080 : 1680 }}" height="{{ $quadradoNoCelular ? 1080 : 640 }}"
                             @if ($loop->first) fetchpriority="high" @else loading="lazy" @endif
                             decoding="async"
                             class="{{ $quadradoNoCelular ? 'aspect-square' : 'aspect-[21/8]' }} w-full object-cover lg:aspect-[21/8]">
                    </picture>
                </{{ $tag }}>
            @endforeach
        </div>

        @if (count($banners) > 1)
            {{-- Setas só no desktop; no celular é arrastar. --}}
            <button type="button" @click="ir(atual - 1)" aria-label="Banner anterior"
                    class="absolute left-3 top-1/2 hidden size-10 -translate-y-1/2 place-items-center rounded-full bg-forno/70 text-2xl text-creme backdrop-blur hover:bg-forno lg:grid">
                <i class='bx bx-chevron-left' aria-hidden="true"></i>
            </button>
            <button type="button" @click="ir(atual + 1)" aria-label="Próximo banner"
                    class="absolute right-3 top-1/2 hidden size-10 -translate-y-1/2 place-items-center rounded-full bg-forno/70 text-2xl text-creme backdrop-blur hover:bg-forno lg:grid">
                <i class='bx bx-chevron-right' aria-hidden="true"></i>
            </button>
        @endif
    </div>

    @if (count($banners) > 1)
        <div class="mt-1 flex justify-center">
            @foreach ($banners as $banner)
                <button type="button" @click="ir({{ $loop->index }})" aria-label="Ver banner {{ $loop->iteration }}"
                        :aria-current="atual === {{ $loop->index }}" class="grid h-7 place-items-center px-1">
                    <span :class="atual === {{ $loop->index }} ? 'w-6 bg-brasa' : 'w-2 bg-linha'" class="block h-2 rounded-full transition-all"></span>
                </button>
            @endforeach
        </div>
    @endif
</section>
