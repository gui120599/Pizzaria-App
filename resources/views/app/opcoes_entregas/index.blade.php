<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bxs-package' ></i>
            <a href="{{route('opcoes_entregas')}}">{{ __('Opções de Entregas') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                     @include('app.opcoes_entregas.partials.create') 
                </div>
            </div>

           <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    @include('app.opcoes_entregas.partials.list') 
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
