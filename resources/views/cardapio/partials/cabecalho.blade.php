{{-- Barra fixa: logo, status da loja, sacola e menu. Na mesa, a faixa da mesa vem junto. --}}
<header id="topo-fixo" class="sticky top-0 z-40 border-b border-linha bg-forno/90 backdrop-blur-md">
    <div class="mx-auto flex h-14 w-full max-w-[1232px] items-center gap-2 px-4 lg:h-[72px] lg:gap-5 lg:px-6">
        <a href="{{ $mesaCliente ? url()->current() : route('cardapio') }}" class="-ml-1 shrink-0" aria-label="Empório da Pizza — início">
            <img src="{{ asset('img/marca/logo.webp') }}"
                 srcset="{{ asset('img/marca/logo.webp') }} 1x, {{ asset('img/marca/logo@2x.webp') }} 2x"
                 alt="Empório da Pizza" width="100" height="45" fetchpriority="high"
                 class="h-10 w-auto lg:h-12">
        </a>

        {{-- Busca no topo do desktop (no celular ela fica na barra de abas) --}}
        <label class="mx-auto hidden h-11 max-w-md flex-1 items-center gap-2.5 rounded-xl border border-linha bg-forno-2 px-3.5 text-farinha focus-within:border-brasa-viva lg:flex">
            <i class='bx bx-search text-xl' aria-hidden="true"></i>
            <span class="sr-only">Buscar no cardápio</span>
            <input type="search" x-model="busca" @input.debounce.150ms="filtrar()" @keydown.escape="limparBusca()"
                   placeholder="Buscar pizza, pastel, bebida ou ingrediente"
                   class="w-full border-0 bg-transparent p-0 text-[15px] text-creme placeholder:text-farinha focus:ring-0">
        </label>

        @unless ($mesaCliente)
            <div class="ml-auto flex items-center gap-1.5 rounded-full border border-linha bg-forno-3 px-2.5 py-1.5 text-xs font-semibold lg:ml-0 lg:px-3 lg:text-[13px]"
                 role="status" aria-live="polite">
                <span class="size-2 rounded-full"
                      :class="$store.cart.statusLoja().aberto ? 'bg-manjericao shadow-[0_0_0_3px_rgb(74_222_128/.18)]' : 'bg-farinha'"></span>
                <span x-text="$store.cart.statusLoja().aberto ? 'Aberto' : 'Fechado'">Aberto</span>
                {{-- Fechado: no celular o horário de abertura aparece no aviso da apresentação. --}}
                <span class="whitespace-nowrap font-medium text-farinha" x-show="$store.cart.statusLoja().texto"
                      :class="!$store.cart.statusLoja().aberto && 'hidden sm:inline'"
                      x-text="'· ' + $store.cart.statusLoja().texto"></span>
            </div>
        @else
            <div class="ml-auto"></div>
        @endunless

        <button type="button" @click="$store.cart.drawerOpen = true"
                class="relative grid size-11 shrink-0 place-items-center rounded-full text-2xl hover:bg-forno-3 lg:hidden"
                x-effect="pulsar($el, $store.cart.pulso)"
                aria-label="Abrir sacola">
            <i class='bx bx-shopping-bag' aria-hidden="true"></i>
            <span x-show="$store.cart.count > 0" x-cloak x-text="$store.cart.count"
                  class="num absolute right-0.5 top-1 grid h-[18px] min-w-[18px] place-items-center rounded-full bg-brasa px-1 text-[11px] font-bold text-white"></span>
        </button>

        @if ($mesaCliente)
            @include('mesa-cliente.botao-painel')
        @else
            <div class="relative shrink-0" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
                <button type="button" @click="menuOpen = !menuOpen" :aria-expanded="menuOpen"
                        class="grid size-11 place-items-center rounded-full text-2xl text-farinha hover:bg-forno-3"
                        aria-label="Menu">
                    <i class='bx bx-dots-vertical-rounded' aria-hidden="true"></i>
                </button>
                <div x-show="menuOpen" x-cloak @click.outside="menuOpen = false" x-transition.origin.top.right
                     class="absolute right-0 z-50 mt-2 w-52 overflow-hidden rounded-2xl border border-linha bg-forno-2 py-1 shadow-xl shadow-black/50">
                    <a href="{{ route('filament.admin.auth.login') }}" class="flex items-center gap-2.5 px-4 py-3 text-sm hover:bg-forno-3">
                        <i class='bx bx-shield-quarter text-lg text-farinha' aria-hidden="true"></i> Painel Admin
                    </a>
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-4 py-3 text-sm hover:bg-forno-3">
                        <i class='bx bx-grid-alt text-lg text-farinha' aria-hidden="true"></i> Dashboard
                    </a>
                </div>
            </div>
        @endif
    </div>

    @if ($mesaCliente)
        @include('mesa-cliente.faixa')
    @endif
</header>
