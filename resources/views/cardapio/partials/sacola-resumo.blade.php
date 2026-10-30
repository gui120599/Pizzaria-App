{{-- Totais da sacola + próxima ação (delivery: dados do pedido; mesa: envio para a conta). --}}
<div class="space-y-2 border-t border-linha px-4 pb-4 pt-3.5">
    @unless ($mesaCliente)
        <template x-if="$store.cart.progressoFrete && $store.cart.items.length > 0">
            <div class="rounded-xl border px-3 py-2.5 text-[13px]"
                 :class="$store.cart.progressoFrete.atingido ? 'border-manjericao/30 bg-manjericao/5' : 'border-linha bg-forno'">
                <p x-show="!$store.cart.progressoFrete.atingido" class="text-farinha">
                    Faltam <b class="num text-creme" x-text="$store.cart.formatar($store.cart.progressoFrete.falta)"></b> para entrega grátis
                </p>
                <p x-show="$store.cart.progressoFrete.atingido" class="flex items-center gap-1.5 font-semibold">
                    <i class='bx bxs-check-circle text-lg text-manjericao' aria-hidden="true"></i> Entrega grátis liberada
                </p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-forno-3">
                    <div class="h-full rounded-full transition-[width] duration-500"
                         :class="$store.cart.progressoFrete.atingido ? 'bg-manjericao' : 'bg-gradient-to-r from-brasa to-ouro'"
                         :style="'width:' + $store.cart.progressoFrete.pct + '%'"></div>
                </div>
            </div>
        </template>
    @endunless

    <div x-show="$store.cart.totalDesconto > 0" x-cloak class="flex justify-between text-sm text-farinha">
        <span>Subtotal</span><span class="num line-through" x-text="$store.cart.formatar($store.cart.totalOriginal)"></span>
    </div>
    <div x-show="$store.cart.totalDesconto > 0" x-cloak class="flex justify-between text-sm font-semibold text-ouro">
        <span><i class='bx bxs-purchase-tag' aria-hidden="true"></i> Descontos de promoção</span>
        <span class="num" x-text="'− ' + $store.cart.formatar($store.cart.totalDesconto)"></span>
    </div>
    <div class="flex items-baseline justify-between">
        <span class="font-semibold">Total</span>
        <span class="num text-xl font-bold" x-text="$store.cart.formatar($store.cart.total)"></span>
    </div>
    @unless ($mesaCliente)
        <p class="text-xs text-farinha">Taxa de entrega calculada na próxima etapa.</p>
    @endunless

    @if ($mesaCliente)
        @include('mesa-cliente.enviar')
    @else
        <button type="button" @click="$store.cart.drawerOpen = true; $store.cart.step = 'checkout'; $store.cart.aplicarCupomPendente()"
                :disabled="$store.cart.items.length === 0 || !$store.cart.estaAbertoAgora()"
                class="botao-brasa h-14 w-full text-base">
            <template x-if="!$store.cart.estaAbertoAgora()">
                <span class="flex items-center gap-2"><i class='bx bx-lock-alt' aria-hidden="true"></i> Pedidos fechados</span>
            </template>
            <template x-if="$store.cart.estaAbertoAgora()">
                <span class="flex items-center gap-1">Continuar <i class='bx bx-chevron-right text-2xl' aria-hidden="true"></i></span>
            </template>
        </button>
    @endif
    <button type="button" @click="$store.cart.clear()" x-show="$store.cart.items.length > 0" x-cloak
            class="w-full py-1.5 text-center text-xs text-farinha hover:text-tomate">
        Esvaziar sacola
    </button>
</div>
