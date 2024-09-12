<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="font-sans antialiased">
    <div class="flex h-screen bg-gray-200">
        <!-- Barra lateral -->
        <div
            class="sidebar z-50 md:block top-0 bottom-0 lg:left-0 p-2 w-[300px] overflow-y-auto text-center bg-black transition-width ease-in-out duration-300">
            @include('layouts.navigation')
        </div>
        <!-- Conteúdo principal -->
        <div class="content flex flex-col flex-1 overflow-hidden transition-margin ease-in-out duration-300 h-[100vh]">
            <!-- Barra de navegação superior fixa -->
            @if (isset($header))
                <header class="bg-transparent h-[5%]">
                    <div class="mx-auto p-1">
                        {{ $header }}
                    </div>
                </header>
                 <!-- Page Content -->
                 <main
                 class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-200 transition-margin ease-in-out duration-300 h-[95%]">
                 <div class="mx-auto h-full">
                     {{ $slot }}

                 </div>
             </main>
                
            @else
                <!-- Page Content -->
                <main
                    class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-200 transition-margin ease-in-out duration-300 h-[100%]">
                    <div class="mx-auto h-full">
                        {{ $slot }}

                    </div>
                </main>
            @endif



            <div>
                <x-toats></x-toats>
            </div>

            <button class="abrir-modal hidden">Modal</button>

        </div>
    </div>
    <!-- Modal -->
    <div
        class="modal-container z-50 hidden h-screen w-full fixed left-0 top-0 flex justify-center items-center bg-black bg-opacity-50 animate-[from_1s_ease-in-out_infinite]">
        <div class="bg-white rounded shadow-lg w-11/12 xl:w-1/2" style="max-height: 80vh; overflow-y: auto;">
            <!-- Modal Header -->
            <div class="border-b px-4 py-2 flex justify-between items-center">
                <div id="modal-title">@yield('title_modal')</div>
                <i class='bx bxs-x-circle fechar-modal cursor-pointer text-4xl' id="fechar-modal"></i>
            </div>
            <!--Modal Body -->
            <div id="modal-body">@yield('content_modal')</div>
        </div>
    </div>

    <script type="module">
        $(document).ready(function() {

            $('.data').mask('00/00/0000');
            $('.time').mask('00:00:00');
            $('.date_time').mask('00/00/0000 00:00:00');
            $('.cep').mask('00000-000');
            $('.phone').mask('0000-0000');
            $('.phone_ddd').mask('(00) 00000-0000');
            $('.phone_us').mask('(000) 000-0000');
            $('.mixed').mask('AAA 000-S0S');
            $('.cpf').mask('000.000.000-00');
            $('.rg').mask('0000000');
            $('.cnpj').mask('00.000.000/0000-00');
            $('.money').mask('00000000,00', {
                reverse: true
            });

            //Função abrir e fechar modal
            //Função abrir e fechar modal
            const modalContainer = $('.modal-container');

            $('.abrir-modal').click(function() {
                modalContainer.toggleClass("hidden");
            });

            $('.fechar-modal, .cancelar-modal').click(function() {
                modalContainer.toggleClass("hidden");
            });
        });

        function updateSidebarPosition() {
            const BtnToggleSideBar = document.querySelector('.toggleSideBar');
            const sidebar = document.querySelector('.sidebar');
            const main = document.querySelector('.content');
            const isMd = window.matchMedia("(min-width: 768px)").matches;

            if (isMd) {
                sidebar.classList.remove('fixed');
                main.classList.remove('ml-20');
                BtnToggleSideBar.classList.remove('rotate-180');
            } else {
                sidebar.classList.add('fixed');
                main.classList.add('ml-20');
                BtnToggleSideBar.classList.add('rotate-180');
            }
        }

        // Chama a função ao carregar a página e redimensionar a tela
        window.addEventListener('load', updateSidebarPosition);
        window.addEventListener('resize', updateSidebarPosition);
    </script>
</body>


</html>
