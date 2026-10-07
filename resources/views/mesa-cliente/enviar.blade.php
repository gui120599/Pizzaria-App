{{-- Envio da rodada pelo celular: vai para a conta da mesa (sem WhatsApp). --}}
<p x-show="$store.mesa.erro" style="display:none"
   class="text-xs text-red-300 bg-red-500/10 border border-red-500/30 rounded-lg px-3 py-2" x-text="$store.mesa.erro"></p>
<p class="text-gray-500 text-[11px] text-center">O pedido entra na conta da mesa. Você paga no fim, com o garçom.</p>
<button @click="$store.mesa.enviar()"
        :disabled="$store.cart.items.length === 0 || !$store.cart.estaAbertoAgora() || $store.mesa.enviando"
        class="w-full py-4 bg-green-500 hover:bg-green-400 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold rounded-xl flex items-center justify-center gap-2 uppercase tracking-wide transition-colors text-sm">
    <template x-if="$store.mesa.enviando">
        <span class="flex items-center gap-2"><i class='bx bx-loader-alt bx-spin text-xl'></i> Enviando...</span>
    </template>
    <template x-if="!$store.mesa.enviando && !$store.cart.estaAbertoAgora()">
        <span class="flex items-center gap-2"><i class='bx bx-lock-alt'></i> Pedidos fechados</span>
    </template>
    <template x-if="!$store.mesa.enviando && $store.cart.estaAbertoAgora()">
        <span class="flex items-center gap-2"><i class='bx bx-send text-lg'></i> Enviar pedido</span>
    </template>
</button>
