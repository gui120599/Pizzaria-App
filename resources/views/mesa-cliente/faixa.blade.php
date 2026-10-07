{{-- Faixa da mesa no topo do cardápio: em que situação o celular está. --}}
<div class="sticky top-0 z-30 -mx-2 mb-2 px-3 py-2 bg-gray-900/95 border-b border-gray-700 backdrop-blur">
    <div class="flex items-center justify-between gap-2">
        <div class="min-w-0">
            <p class="text-white text-sm font-bold leading-tight truncate">
                <i class='bx bx-table text-green-400'></i>
                <span x-text="$store.mesa.estado.mesa.nome"></span>
                <span x-show="$store.mesa.estado.participante" style="display:none" class="text-gray-400 font-normal"
                      x-text="'· ' + ($store.mesa.estado.participante?.nome ?? '')"></span>
            </p>
            <p class="text-gray-400 text-[11px] leading-tight" x-text="$store.mesa.legenda()"></p>
        </div>

        <template x-if="$store.mesa.estado.estado === 'pedindo'">
            <button type="button" @click="$store.mesa.chamar('CHAMAR_GARCOM')"
                    :disabled="$store.mesa.chamadoAberto('CHAMAR_GARCOM') || $store.mesa.ocupado"
                    class="shrink-0 min-h-[40px] px-3 rounded-xl text-xs font-bold flex items-center gap-1 transition-colors"
                    :class="$store.mesa.chamadoAberto('CHAMAR_GARCOM') ? 'bg-gray-700 text-gray-300' : 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'">
                <i class='bx bx-bell text-base'></i>
                <span x-text="$store.mesa.chamadoAberto('CHAMAR_GARCOM') ? 'Garçom chamado' : 'Chamar garçom'"></span>
            </button>
        </template>

        <template x-if="$store.mesa.estado.estado === 'identificar'">
            <button type="button" @click="$store.mesa.identificar()"
                    class="shrink-0 min-h-[40px] px-3 rounded-xl text-xs font-bold bg-green-500 text-white hover:bg-green-400 flex items-center gap-1">
                <i class='bx bx-user text-base'></i> Entrar na mesa
            </button>
        </template>

        <template x-if="$store.mesa.estado.estado === 'fechada'">
            <button type="button" @click="$store.mesa.pedirAbertura()"
                    :disabled="$store.mesa.estado.abertura_solicitada || $store.mesa.ocupado"
                    class="shrink-0 min-h-[40px] px-3 rounded-xl text-xs font-bold flex items-center gap-1 transition-colors"
                    :class="$store.mesa.estado.abertura_solicitada ? 'bg-gray-700 text-gray-300' : 'bg-yellow-400 text-gray-900 hover:bg-yellow-300'">
                <i class='bx bx-bell text-base'></i>
                <span x-text="$store.mesa.estado.abertura_solicitada ? 'Garçom avisado' : 'Chamar garçom para abrir'"></span>
            </button>
        </template>
    </div>

    <p x-show="$store.mesa.aviso" style="display:none" x-transition
       class="mt-2 text-xs text-green-300 bg-green-500/10 border border-green-500/30 rounded-lg px-2.5 py-1.5" x-text="$store.mesa.aviso"></p>
    <p x-show="$store.mesa.erro && !$store.cart.drawerOpen" style="display:none"
       class="mt-2 text-xs text-red-300 bg-red-500/10 border border-red-500/30 rounded-lg px-2.5 py-1.5" x-text="$store.mesa.erro"></p>
</div>
