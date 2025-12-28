<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bx-chair'></i>
            <a href="{{ route('sessao_caixa') }}">{{ __('Sessões de Caixa') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full  mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 flex flex-col lg:flex-row space-y-5 lg:space-y-0 items-center sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full lg:w-2/6">
                    <!-- Incluir formulário para criar nova mesa -->
                    @include('app.sessao_caixa.partials.create')
                    
                </div>
                <div class="w-full lg:w-4/6">
                    <!-- Incluir lista de mesas -->
                    @include('app.sessao_caixa.partials.list')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
