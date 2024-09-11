<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bx-note'></i>
            <a href="{{route('nota_fiscal')}}">{{ __('NotasFiscais Emitididas') }}</a>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="w-full mx-auto sm:px-6 lg:px-2 space-y-6">
           <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    @include('app.nota_fiscal.partials.list') 
                </div>
            </div>

           <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    @include('app.nota_fiscal.partials.listError') 
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
