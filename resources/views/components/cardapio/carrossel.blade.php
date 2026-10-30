@props(['id', 'titulo', 'subtitulo' => null, 'icone' => null])

{{-- Seção com trilho horizontal (vitrine). Setas só no desktop; no celular é arrastar. --}}
<section id="{{ $id }}" data-secao data-ocultar-na-busca x-data class="pt-6">
    <div class="flex items-end gap-3 px-4 lg:px-0">
        <div class="min-w-0">
            <h2 class="flex items-center gap-2 font-display text-2xl font-bold tracking-tight lg:text-[26px]">
                @if ($icone)<i class='bx {{ $icone }} text-[22px] text-ouro' aria-hidden="true"></i>@endif
                {{ $titulo }}
            </h2>
            @if ($subtitulo)
                <p class="mt-0.5 text-[13.5px] text-farinha">{{ $subtitulo }}</p>
            @endif
        </div>
        {{ $acoes ?? '' }}
        <div class="ml-auto hidden gap-2 lg:flex">
            <button type="button" @click="$refs.trilho.scrollBy({ left: -$refs.trilho.clientWidth * .8, behavior: 'smooth' })"
                    class="grid size-9 place-items-center rounded-full border border-linha text-xl text-farinha hover:text-creme" aria-label="Anteriores">
                <i class='bx bx-chevron-left' aria-hidden="true"></i>
            </button>
            <button type="button" @click="$refs.trilho.scrollBy({ left: $refs.trilho.clientWidth * .8, behavior: 'smooth' })"
                    class="grid size-9 place-items-center rounded-full border border-linha text-xl text-farinha hover:text-creme" aria-label="Próximos">
                <i class='bx bx-chevron-right' aria-hidden="true"></i>
            </button>
        </div>
    </div>
    <div x-ref="trilho" class="rolagem-x mt-3 flex snap-x gap-3 scroll-px-4 px-4 pb-6 lg:scroll-px-0 lg:px-0">
        {{ $slot }}
    </div>
</section>
