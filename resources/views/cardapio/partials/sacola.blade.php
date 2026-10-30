{{--
    Sheet da sacola: celular sobe de baixo; desktop abre à direita. Etapas:
    cart (itens) → checkout (dados, delivery) → sucesso (pedido gravado, com o
    link do WhatsApp que não depende do pop-up automático).
--}}
<div x-show="$store.cart.drawerOpen" x-cloak
     class="fixed inset-0 z-50 flex items-end justify-center lg:items-stretch lg:justify-end"
     role="dialog" aria-modal="true" aria-labelledby="titulo-sacola"
     @keydown.escape.window="$store.cart.drawerOpen && !$store.cart.trocoModal.open && $store.cart.fecharDrawer()">
    <div x-show="$store.cart.drawerOpen" x-transition.opacity @click="$store.cart.fecharDrawer()" class="absolute inset-0 bg-black/70"></div>

    <div x-show="$store.cart.drawerOpen"
         x-transition:enter="transition duration-300 ease-[cubic-bezier(.2,.8,.2,1)]" x-transition:enter-start="translate-y-full lg:translate-x-full lg:translate-y-0"
         x-transition:leave="transition duration-200 ease-in" x-transition:leave-end="translate-y-full lg:translate-x-full lg:translate-y-0"
         class="relative flex max-h-[92dvh] w-full flex-col rounded-t-sheet border-t border-linha bg-forno-2 sm:max-w-lg lg:h-full lg:max-h-none lg:max-w-[460px] lg:rounded-none lg:border-l lg:border-t-0">
        <div class="mx-auto mt-2.5 h-1 w-10 shrink-0 rounded-full bg-linha lg:hidden"></div>

        <div class="flex shrink-0 items-center gap-2 px-4 pb-3 pt-2 lg:pt-5">
            <button type="button" x-show="$store.cart.step === 'checkout'" x-cloak @click="$store.cart.step = 'cart'"
                    class="-ml-2 grid size-10 place-items-center rounded-full text-2xl text-farinha hover:text-creme" aria-label="Voltar para a sacola">
                <i class='bx bx-arrow-back' aria-hidden="true"></i>
            </button>
            <div>
                <h2 id="titulo-sacola" class="font-display text-2xl font-bold"
                    x-text="{ cart: '{{ $mesaCliente ? 'Seu pedido' : 'Sua sacola' }}', checkout: 'Finalizar pedido', sucesso: 'Pedido recebido' }[$store.cart.step]"></h2>
                <p x-show="$store.cart.step === 'cart' && $store.cart.count > 0" class="text-[13px] text-farinha"
                   x-text="$store.cart.count + ($store.cart.count === 1 ? ' item' : ' itens')"></p>
            </div>
            <button type="button" @click="$store.cart.fecharDrawer()"
                    class="ml-auto grid size-10 place-items-center rounded-full bg-forno-3 text-2xl text-farinha hover:text-creme" aria-label="Fechar">
                <i class='bx bx-x' aria-hidden="true"></i>
            </button>
        </div>

        {{-- ── Etapa 1: itens ── --}}
        <div x-show="$store.cart.step === 'cart'" class="flex min-h-0 flex-1 flex-col">
            @if ($mesaCliente)
                <template x-if="!$store.cart.estaAbertoAgora()">
                    <div class="mx-4 mb-2 flex items-start gap-3 rounded-xl border border-brasa-viva/30 bg-brasa/10 px-3.5 py-3">
                        <i class='bx bx-lock-alt mt-0.5 text-xl text-brasa-viva' aria-hidden="true"></i>
                        <p class="text-sm font-semibold" x-text="$store.mesa.mensagemBloqueio()"></p>
                    </div>
                </template>
            @else
                <template x-if="!$store.cart.estaAbertoAgora()">
                    <div class="mx-4 mb-2 flex items-start gap-3 rounded-xl border border-brasa-viva/30 bg-brasa/10 px-3.5 py-3">
                        <i class='bx bx-time-five mt-0.5 text-xl text-brasa-viva' aria-hidden="true"></i>
                        <div>
                            <p class="text-sm font-semibold">Estamos fechados no momento</p>
                            <p class="mt-0.5 text-xs text-farinha" x-text="$store.cart.proximoHorarioAgora() ? 'Voltamos ' + $store.cart.proximoHorarioAgora() : ''"></p>
                        </div>
                    </div>
                </template>
            @endif
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                @include('cardapio.partials.sacola-itens')
            </div>
            <div class="shrink-0 pb-[env(safe-area-inset-bottom)]">
                @include('cardapio.partials.sacola-resumo')
            </div>
        </div>

        @unless ($mesaCliente)
            {{-- ── Etapa 2: dados do pedido ── --}}
            <div x-show="$store.cart.step === 'checkout'" x-cloak class="flex min-h-0 flex-1 flex-col">
                @include('cardapio.partials.checkout')
            </div>

            {{-- ── Etapa 3: sucesso ── --}}
            <div x-show="$store.cart.step === 'sucesso'" x-cloak class="flex min-h-0 flex-1 flex-col overflow-y-auto">
                @include('cardapio.partials.sucesso')
            </div>
        @endunless
    </div>
</div>
