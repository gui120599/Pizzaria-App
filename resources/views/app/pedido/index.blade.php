<x-app-layout>
    <div class="py-2 h-full">
        <div class="max-w-7xl h-full flex-row mx-auto sm:px-6 lg:px-2">
            <div class="w-full p-2 sm:rounded-lg">
                <div class="w-full bg-white">
                    <button>Cliente</button>
                </div>
            </div>
            <div class="flex h-[90%]">
                <div class="w-1/2 p-2 h-full sm:rounded-lg">
                    <div class="w-full h-full overflow-auto">
                        @livewire('list-produtos')
                    </div>
                </div>
                <div class="w-1/4 p-2 h-full sm:rounded-lg">
                    <div class="w-full h-full">
                        @include('app.pedido.partials.listProdutosPedido')
                    </div>
                </div>
                <div class="w-1/4 p-2 h-full sm:rounded-lg">
                    <div class="w-full h-full">
                        @include('app.pedido.partials.listProdutosPedido')
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
