<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight flex items-center space-x-2">
            <i class='bx bx-x-circle'></i>
            <span>Usuário sem permissão de acesso!</span>
        </h2>
    </x-slot>
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    <div class="text-center">
                        <h1 class="text-5xl font-bold text-red-600">403</h1>
                        <p class="text-xl mt-4">Você não tem permissão para acessar esta página.</p>
                        <a href="{{ route('dashboard') }}"
                            class="mt-6 inline-block text-blue-500 hover:underline">Voltar ao Painel de Controle</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
