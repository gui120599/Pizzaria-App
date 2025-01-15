<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center justify-between space-x-2">
            <i class='bx bx-chair'></i>
            <a href="{{ route('mesa') }}">{{ __('Realizar Pedido para') }} {{ $mesa->mesa_nome }} - ID Sessão: {{ $sessao_mesa->id }} - {{ __('Novo Pedido') }} - <span id="pedido_id_titulo"></span></a>
            
                <div class="flex space-x-2">
                
                <a href="{{ route('sessaoMesa.editAlterarMesa', ['sessaoMesa' => $sessao_mesa]) }}"
                    class="text-center px-4 py-2 bg-gray-200 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">Alterar
                    Mesa</a>
                
                <x-secondary-button id="btn-imprimir">IMPRIMIR</x-secondary-button>
                
                <form action="{{ route('sessaoMesa.fechar', ['sessaoMesa' => $sessao_mesa]) }}" method="get">
                    @method('patch')
                    <x-primary-button>FECHAR MESA</x-primary-button>
                </form>

            </div>
        </h2>
    </x-slot>

    <div class="py-2 h-full ">
        <div class="max-w-full h-full mx-auto sm:px-6 lg:px-8 space-y-6 ">
            <div class="p-1 sm:p-2 h-full bg-white shadow sm:rounded-lg overflow-auto">
                <div class="w-full h-full">
                    <!-- Incluir formulário para criar nova mesa -->
                    @include('app.sessao_mesa.partials.pedido')
                </div>
            </div>
        </div>

    </div>
    <script type="module">
        $("#btn-imprimir").click(function(e) {
            e.preventDefault();
            window.open('{{ route('sessaoMesa.imprimir', ['id' => $sessao_mesa->id]) }}', 'Teste',
                'width=600,height=400');
        });
    </script>
</x-app-layout>
