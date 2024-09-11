<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bx-chair'></i>
            <a href="{{ route('mov_saida') }}">{{ __('Saidas de Caixa') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <!-- Incluir formulário para criar nova mesa -->
                    @include('app.saida_caixa.partials.create')
                    
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    <!-- Incluir lista de mesas -->
                    @include('app.saida_caixa.partials.list')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
