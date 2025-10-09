<div
    class="relative rounded-lg overflow-auto sm:col-span-8 lg:col-span-3 col-span-6 bg-slate-100 border h-full flex flex-col justify-between">
    <div class="bg-white p-1">
        <p>Itens do Pedido</p>
    </div>
    <div class="relative overflow-auto h-full">
        <!-- Ícone de carregamento e mensagem -->
        <div id="carregando"
            class="h-full hidden absolute inset-0 flex justify-center items-center bg-slate-600 bg-opacity-50 transition duration-150 ease-in-out">
            <div class="text-center h-full">
                <i class='bx bx-loader-circle bx-spin bx-rotate-90 text-5xl'></i>
                <p>Carregando Produtos</p>
            </div>
        </div>

        <div id="itens_pedido_container" class="">


            <!-- Conteúdo -->
            <p class="p-2 text-center">Nenhum produto lançado no pedido!</p>
        </div>
    </div>
    {{-- Valores --}}
    <div class="bg-white p-1">
        <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
            <i class='bx bx-dollar-circle'></i>
            <span>{{ __('Valores') }}</span>
        </p>
        <div class="grid grid-cols-1 md:grid-cols-3 lg:space-x-2">
            <div class="col-span-1">
                <x-input-label for="pedido_valor_itens" :value="__('Itens R$')" />
                <x-money-input id="pedido_valor_itens" name="pedido_valor_itens" type="text" class="mt-1 w-full"
                    autocomplete="off" value="0.00" readonly />
            </div>
            <div class="col-span-1">
                <x-input-label for="pedido_valor_desconto" :value="__('Desconto R$')" />
                <x-money-input id="pedido_valor_desconto" name="pedido_valor_desconto" type="text"
                    class="money mt-1 w-full" autocomplete="off" value="0.00" readonly />
            </div>
            <div class="col-span-1">
                <x-input-label for="pedido_valor_total" :value="__('Total R$')" />
                <x-money-input id="pedido_valor_total" name="pedido_valor_total" type="text" class="mt-1 w-full"
                    autocomplete="off" value="0.00" readonly />
            </div>
        </div>
    </div>
</div>
