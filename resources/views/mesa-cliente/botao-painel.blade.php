{{-- Topo da página da mesa: abre "Minha mesa" (pedidos, conta e chamados). --}}
<button type="button"
        @click="$store.mesa.abrirPainel()"
        class="relative mt-2 text-white text-3xl focus:outline-none"
        aria-label="Minha mesa">
    <i class='bx bx-receipt'></i>
    <span x-show="$store.mesa.aguardando > 0" style="display:none"
          class="absolute -top-1 -right-1 bg-yellow-400 text-gray-900 text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center"
          x-text="$store.mesa.aguardando"></span>
</button>
