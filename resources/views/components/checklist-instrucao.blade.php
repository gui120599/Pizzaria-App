@props(['texto', 'recolher' => true])

{{-- Instrução com quebras de linha; recolhida em 2 linhas quando passa disso. --}}
<div x-data="{ aberta: false, longa: false }"
     x-init="$nextTick(() => { longa = $refs.texto.scrollHeight > $refs.texto.clientHeight + 1 })">
    <p x-ref="texto"
       @class(['whitespace-pre-line text-sm text-gray-600 dark:text-gray-300', 'line-clamp-2' => $recolher])
       @if ($recolher) x-bind:class="aberta ? '!line-clamp-none' : ''" @endif>{{ $texto }}</p>
    @if ($recolher)
        <button type="button" x-show="longa" x-cloak x-on:click="aberta = ! aberta"
                class="mt-0.5 text-sm font-semibold text-primary-600 dark:text-primary-400"
                x-text="aberta ? 'Recolher' : 'Ver instrução completa'"></button>
    @endif
</div>
