<x-app-layout>
    <x-slot name="header">
        
        <nav class="bg-transparent border-b border-gray-100">
            <!-- Primary Navigation Menu -->
            <div class="w-full mx-auto sm:px-6 lg:px-2">
                <div class="flex justify-center align-top h-8">
                    <!-- Navigation Links -->
                    <div class="space-x-8 sm:ms-10 flex ">
                        <div class="nav-link active cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:border-white focus:outline-none focus:text-white focus:border-white transition duration-150 ease-in-out"
                            data-section="estoque-section">
                            <div class="flex items-center">
                                <i class='bx bxs-package me-2'></i>
                                {{ __('Estoque') }}
                            </div>
                        </div>

                        <div class="nav-link cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:border-white focus:outline-none focus:text-white focus:border-white transition duration-150 ease-in-out"
                            data-section="cadastrar-produto-section">
                            <div class="flex items-center">
                                <i class='bx bx-plus me-2'></i>
                                {{ __('Cadastrar Produto') }}
                            </div>
                        </div>

                        <div class="nav-link cursor-pointer inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-light leading-5 text-gray-500 hover:border-white focus:outline-none focus:text-white focus:border-white transition duration-150 ease-in-out"
                            data-section="entradas-section">
                            <a href="{{ route('entrada_produto') }}" class="flex items-center">
                                <i class='bx bx-plus me-2'></i>
                                {{ __('Entradas') }}
                            </a>
                        </div>

                    </div>
                </div>
            </div>
        </nav>
    </x-slot>

    <div class="py-2 h-full">
        <div class="w-full h-full mx-auto sm:px-6 lg:px-2">
            <div class="p-2 h-full bg-white shadow sm:rounded-lg cadastrar-produto-section">
                <div class="w-full h-full">
                    @include('app.produto.partials.create')
                </div>
            </div>

            <div class="h-full p-2 bg-white shadow sm:rounded-lg estoque-section">
                <div class="w-full h-full">
                    @include('app.produto.partials.list')
                </div>
            </div>
        </div>
    </div>
    <style>
        .nav-link.active {
            /* Adicione estilos desejados para o link ativo aqui */
            color: rgb(20 184 166 / var(--tw-bg-opacity));
            /* Exemplo: altera a cor do texto para branco */
            border-bottom-color: rgb(20 184 166 / var(--tw-bg-opacity));
            /* Exemplo: altera a cor da borda inferior para branco */
            font-weight: bold;
        }
    </style>
    <script type="module">
    $(document).ready(function() {
        // Oculta todas as seções ao carregar a página
        $('.cadastrar-produto-section').hide();

        // Mostra a seção correspondente quando um link da navegação é clicado
        $('.nav-link').click(function() {
            var targetSection = $(this).data('section');
            
            // Oculta todas as seções e mostra apenas a correspondente
            $('.w-full .bg-white').hide();
            $('.' + targetSection).show();

            // Destaca visualmente o link ativo
            $('.nav-link').removeClass('active');
            $(this).addClass('active');
        });
    });
    </script>
</x-app-layout>
