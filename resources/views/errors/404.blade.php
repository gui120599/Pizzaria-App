<x-guest-layout>
    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="w-full">
                    <div class="text-center">
                        <h1 class="text-5xl font-bold text-red-600">404</h1>
                        <p class="text-xl mt-4">Página não encontrada.</p>
                        <a href="{{ route('cardapio') }}"
                            class="mt-6 inline-block text-blue-500 hover:underline">Voltar para Página Principal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
