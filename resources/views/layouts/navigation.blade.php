<div class="text-gray-100 text-xl">
    <div class="p-2.5 mt-1 flex items-center">
        <div class="h-16 w-full flex flex-row items-center justify-between text-white text-2xl">
            <a href="{{ route('dashboard') }}">
                <x-application-logo class="block h-16 w-auto fill-current text-gray-800 logo" />
            </a>
            <i class='bx bx-arrow-to-left px-2 py-2 bg-teal-500 rounded-md cursor-pointer hover:bg-teal-900 toggleSideBar'
                onclick="toggleSidebar()"></i>
        </div>
    </div>
    <hr class="h-px my-2 border-0 dark:bg-gray-700">

    <!-- Nav-link´s-->
    <!--<div class="p2.5 mt-3 flex items-center rounded-md px-4 durations-300 cursor-pointer bg-gray-700">
        <i class='bx bx-search text-sm'></i>
        <input type="text" placeholder="Buscar"
            class="text-[15px] ml-4 w-full bg-transparent border-none focus:border-transparent focus:ring-0">
    </div>-->
    <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
        <i class='bx bxs-dashboard'></i>
        <span class="text-[15px] ml-4 text-gray-200">{{ __('Dashboard') }}</span>
    </x-nav-link>
    <x-nav-link :href="route('venda')" :active="request()->routeIs('venda')">
        <i class='bx bx-money-withdraw'></i>
        <span class="text-[15px] ml-4 text-gray-200">{{ __('Venda') }}</span>
    </x-nav-link>
    <x-nav-link :href="route('pedido')" :active="request()->routeIs('pedido')">
        <i class="bx bx-basket"></i>
        <span class="text-[15px] ml-4 text-gray-200">{{ __('Pedidos') }}</span>
    </x-nav-link>
    <x-nav-link :href="route('pedidos.abertos')" :active="request()->routeIs('pedidos.abertos')">
        <i class='bx bx-receipt'></i>
        <span class="text-[15px] ml-4 text-gray-200">{{ __('Pedidos Abertos') }}</span>
    </x-nav-link>
    <x-nav-link :href="route('produto')" :active="request()->routeIs('produto')">
        <i class='bx bxs-pizza'></i>
        <span class="text-[15px] ml-4 text-gray-200">{{ __('Produtos') }}</span>
    </x-nav-link>
    <x-nav-link :href="route('cliente')" :active="request()->routeIs('cliente')">
        <i class='bx bx-user'></i>
        <span class="text-[15px] ml-4 text-gray-200">{{ __('Clientes') }}</span>
    </x-nav-link>
    @can('Admin')
        <x-nav-link :href="route('categoria')" :active="request()->routeIs('categoria')">
            <i class='bx bx-user-pin'></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Usuários') }}</span>
        </x-nav-link>
    @endcan
    <hr class="h-px my-2 border-0 dark:bg-gray-700">

    <!--Financeiro-->
    <div class="p-2.5 mt-3 flex items-center rounded-md px-4 duration-300 cursor-pointer hover:bg-teal-700 text-white"
        onclick="dropdown('finan')">
        <i class='bx bx-money-withdraw'></i>
        <div class="flex justify-between w-full items-center">
            <span class="text-[15px] ml-4 text-gray-200">Financeiro</span>
            <span class="transition-transform" id="arrow-finan">
                <i class='bx bx-chevron-up '></i>
            </span>
        </div>
    </div>
    <div class="pl-4 text-sm font-thin mt-2 w-4/5 mx-auto transition duration-700 ease-in-out" id="submenu-finan">
        <x-nav-link :href="route('caixa')" :active="request()->routeIs('caixa')">
            <i class='bx bx-dollar'></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Caixa') }}</span>
        </x-nav-link>
        <x-nav-link :href="route('sessao_caixa')" :active="request()->routeIs('sessao_caixa')">
            <i class='bx bx-money'></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Sessões de Caixa') }}</span>
        </x-nav-link>
    </div>
    <hr class="h-px my-2 border-0 dark:bg-gray-700">

    <!--Configurações Gerais-->
    <div class="p-2.5 mt-3 flex items-center rounded-md px-4 duration-300 cursor-pointer hover:bg-teal-700 text-white"
        onclick="dropdown('config')">
        <i class='bx bxs-cog'></i>
        <div class="flex justify-between w-full items-center">
            <span class="text-[15px] ml-4 text-gray-200">Configurações Gerais</span>
            <span class="transition-transform" id="arrow-config">
                <i class='bx bx-chevron-up '></i>
            </span>
        </div>
    </div>
    <div class="text-sm font-thin mt-2 w-4/5 mx-auto transition duration-700 ease-in-out" id="submenu-config">
        <x-nav-link :href="route('empresa')" :active="request()->routeIs('empres')">
            <i class='bx bx-building-house'></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Empresa') }}</span>
        </x-nav-link>
        <x-nav-link :href="route('categoria')" :active="request()->routeIs('categoria')">
            <i class='bx bx-category-alt'></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Categorias') }}</span>
        </x-nav-link>
        <x-nav-link :href="route('opcoes_pagamento')" :active="request()->routeIs('opcoes_pagamento')">
            <i class='bx bxs-credit-card' ></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Opções de Pagamentos') }}</span>
        </x-nav-link>
        <x-nav-link :href="route('opcoes_entregas')" :active="request()->routeIs('opcoes_entregas')">
            <i class='bx bx-package'></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Opções de Entrega') }}</span>
        </x-nav-link>
        <x-nav-link :href="route('mesa')" :active="request()->routeIs('mesa.index')">
            <i class='bx bx-chair'></i>
            <span class="text-[15px] ml-4 text-gray-200">{{ __('Mesas') }}</span>
        </x-nav-link>
    </div>
    <hr class="h-px my-2 border-0 dark:bg-gray-700">

    <!-- Profile -->
    <div class="flex items-center">
        <form method="POST" class="w-full" action="{{ route('logout') }}">
            @csrf
            <x-nav-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                <i class='bx bx-log-out-circle'></i>
                <span class="text-[11px] ml-4 text-gray-200 uppercase">{{ __('Sair do Sistema') }}</span>
            </x-nav-link>
        </form>
    </div>
    <x-nav-link :href="route('profile.edit')" :active="request()->routeIs('profile.edit')">
        <i class='bx bx-user-circle'></i>
        <span class="text-[11px] ml-4 text-gray-200">{{ Auth::user()->name }}</span>
    </x-nav-link>
    <style>
        /* Adicione estas regras CSS no seu arquivo de estilo ou diretamente na tag <style> no head do HTML */
        .sidebar {
            width: 300px;
            transition: width 0.5s ease;
        }

        .sidebar-hidden {
            width: 68px;
            overflow: hidden;
        }

        .logo-hidden {
            opacity: 0;
            transition: opacity 0.5s ease;
        }

        .nav-link-hidden {
            opacity: 0;
            transition: opacity 0.5s ease;
        }
    </style>
    <script>
        function toggleSidebar() {
            const BtnToggleSideBar = document.querySelector('.toggleSideBar');
            const sidebar = document.querySelector('.sidebar');
            const logo = document.querySelector('.logo');
            const navLinks = document.querySelectorAll('.sidebar span');

            BtnToggleSideBar.classList.toggle('rotate-180');
            sidebar.classList.toggle('sidebar-hidden');
            logo.classList.toggle('logo-hidden');
            navLinks.forEach(span => {
                span.classList.toggle('hidden');
            });
        }

        function toogleSidebarsm() {
            const isMd = window.matchMedia("(min-width: 768px)").matches;
            const BtnToggleSideBar = document.querySelector('.toggleSideBar');
            const sidebar = document.querySelector('.sidebar');
            const logo = document.querySelector('.logo');
            const navLinks = document.querySelectorAll('.sidebar span');

            if (isMd) {

            } else {
                BtnToggleSideBar.classList.add('rotate-0');
                sidebar.classList.add('sidebar-hidden');
                logo.classList.add('logo-hidden');
                navLinks.forEach(span => {
                    span.classList.add('hidden');
                });
            }
        }
        toogleSidebarsm();


        function dropdown(prefix) {
            const submenu = document.querySelector('#submenu-' + prefix);
            const arrow = document.querySelector('#arrow-' + prefix);

            submenu.classList.toggle('hidden');
            arrow.classList.toggle('rotate-180');
        }

        dropdown('finan');
        dropdown('config');
    </script>
</div>
