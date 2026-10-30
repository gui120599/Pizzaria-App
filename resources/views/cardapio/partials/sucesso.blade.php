{{--
    Pedido gravado. O WhatsApp abre sozinho quando o navegador deixa; se o
    pop-up for bloqueado, o link abaixo garante o envio da mensagem.
--}}
<div class="flex flex-1 flex-col items-center px-6 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-4 text-center">
    <div class="grid size-20 place-items-center rounded-full bg-manjericao/10">
        <i class='bx bxs-check-circle text-5xl text-manjericao' aria-hidden="true"></i>
    </div>
    <p class="mt-4 font-display text-[28px] font-bold leading-tight">
        Pedido nº <span class="num" x-text="$store.cart.ultimoPedido?.id"></span>
    </p>
    <p class="mt-2 max-w-xs text-[15px] text-farinha">
        Falta só enviar a mensagem para a loja confirmar o seu pedido no WhatsApp.
    </p>

    <div class="mt-6 w-full max-w-sm rounded-2xl border border-linha bg-forno p-4 text-left text-sm">
        <div class="flex justify-between text-farinha">
            <span x-text="$store.cart.ultimoPedido?.recebimento"></span>
            <span class="num" x-text="($store.cart.ultimoPedido?.frete ?? 0) > 0 ? 'Entrega ' + $store.cart.formatar($store.cart.ultimoPedido.frete) : ''"></span>
        </div>
        <div class="mt-1 flex items-baseline justify-between">
            <span class="font-semibold">Total</span>
            <span class="num text-lg font-bold" x-text="$store.cart.formatar($store.cart.ultimoPedido?.total)"></span>
        </div>
    </div>

    <a :href="$store.cart.ultimoPedido?.linkWhatsapp" target="_blank" rel="noopener"
       class="mt-6 inline-flex h-14 w-full max-w-sm items-center justify-center gap-2 rounded-2xl bg-whats text-base font-bold text-white transition hover:bg-whats-escuro">
        <i class='bx bxl-whatsapp text-2xl' aria-hidden="true"></i> Enviar no WhatsApp
    </a>
    <a :href="$store.cart.ultimoPedido?.linkAcompanhar"
       class="botao-secundario mt-3 h-12 w-full max-w-sm text-[15px]">
        <i class='bx bx-map-pin' aria-hidden="true"></i> Acompanhar pedido
    </a>
    <button type="button" @click="$store.cart.novoPedido()" class="mt-4 text-sm font-semibold text-farinha hover:text-creme">
        Voltar ao cardápio
    </button>
</div>
