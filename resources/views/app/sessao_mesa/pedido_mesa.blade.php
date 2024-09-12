<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center justify-between space-x-2">
            <i class='bx bx-chair'></i>
            <a href="{{ route('mesa') }}">{{ __('Realizar Pedido para') }} {{ $mesa->mesa_nome }} - ID Sessão:
                {{ $sessao_mesa->id }} - {{ __('Novo Pedido') }} - <span id="pedido_id_titulo"></span></a>
            <x-secondary-button id="btn-imprimir">IMPRIMIR</x-secondary-button>
        </h2>
    </x-slot>

    <div class="py-2 h-full">
        <div class="max-w-full h-full mx-auto sm:px-6 lg:px-8 space-y-6 snap-y">

            <div class="p-1 sm:p-2 h-full bg-white shadow sm:rounded-lg overflow-auto">
                <div class="w-full h-full">
                    <!-- Incluir formulário para criar nova mesa -->
                    @include('app.sessao_mesa.partials.pedido')
                </div>
            </div>

            <div class="p-1 h-full sm:p-2 bg-white shadow sm:rounded-lg overflow-auto">
                <div class="w-full h-full">
                    <!-- Incluir lista de mesas -->
                    @include('app.sessao_mesa.partials.list')
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
