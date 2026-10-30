@props(['rotulo' => 'opções'])

{{--
    Trilho horizontal de pílulas (subcategorias, abas). A barra de rolagem
    fica oculta; quando o conteúdo não cabe, aparecem setas e um esmaecimento
    nas bordas — no desktop é o único jeito de chegar ao fim sem arrastar.
--}}
<div x-data="trilhoRolavel" {{ $attributes->merge(['class' => 'relative min-w-0']) }}>
    <div x-ref="trilho" @scroll.passive="medir()" class="rolagem-x flex gap-2">
        {{ $slot }}
    </div>

    <div x-show="podeVoltar" x-cloak aria-hidden="true"
         class="pointer-events-none absolute inset-y-0 left-0 w-14 bg-gradient-to-r from-forno via-forno/80 to-transparent"></div>
    <div x-show="podeAvancar" x-cloak aria-hidden="true"
         class="pointer-events-none absolute inset-y-0 right-0 w-14 bg-gradient-to-l from-forno via-forno/80 to-transparent"></div>

    <button type="button" x-show="podeVoltar" x-cloak @click="rolar(-1)" aria-label="Ver {{ $rotulo }} anteriores"
            class="absolute left-0 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-full border border-linha bg-forno-3 text-xl text-creme shadow-lg shadow-black/50 hover:border-farinha/50">
        <i class='bx bx-chevron-left' aria-hidden="true"></i>
    </button>
    <button type="button" x-show="podeAvancar" x-cloak @click="rolar(1)" aria-label="Ver mais {{ $rotulo }}"
            class="absolute right-0 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-full border border-linha bg-forno-3 text-xl text-creme shadow-lg shadow-black/50 hover:border-farinha/50">
        <i class='bx bx-chevron-right' aria-hidden="true"></i>
    </button>
</div>
