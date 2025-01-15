<x-app-layout>
    <div class="py-2 h-full ">
        <div class="max-w-full h-full mx-auto sm:px-6 lg:px-8 space-y-6 ">
            <div class="p-1 h-full sm:p-2 bg-white shadow sm:rounded-lg overflow-auto">
                <div class="w-full h-full">
                    <!-- Incluir lista de mesas -->
                    @include('app.sessao_mesa.partials.list_itens')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
