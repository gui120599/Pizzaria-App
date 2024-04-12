<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Produto') }}
        </h2>
    </x-slot>

    <div class="">
        <div class="w-full sm:w-1/2 mx-auto sm:px-1 lg:px-2 space-y-6">
            <div class="bg-white p-1 rounded-lg shadow-md">
                <img src="{{ asset('img/fotos_produtos/' . $produto->produto_foto) }}" alt="{{ $produto->produto_nome }}" class="w-full h-40 object-contain mb-4">
                <h2 class="text-lg font-semibold">{{ $produto->produto_descricao }}</h2>
                <p class="text-gray-600">{{ $produto->produto_descricao }}</p>
                <!-- Adicione mais detalhes conforme necessário -->
            </div>
        </div>
    </div>
</x-app-layout>
