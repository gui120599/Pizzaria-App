<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-white/10 p-4 space-y-3">
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Cliente</h3>
        <button type="button" wire:click="$set('buscaModalAberta', true)"
            class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400">
            Buscar cliente
        </button>
    </div>

    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
        <input type="checkbox" wire:model.live="semCliente"
            class="h-4 w-4 rounded border-gray-300 dark:border-white/20 dark:bg-gray-900 text-primary-600 focus:ring-primary-500">
        Pedido sem cliente cadastrado
    </label>

    <div class="space-y-2 {{ $semCliente ? 'opacity-40 pointer-events-none' : '' }}">
        <div class="relative">
            <input type="tel" wire:model.live.debounce.500ms="celular" placeholder="(11) 99999-9999"
                x-on:input="$el.value = window.maskPhone ? window.maskPhone($el.value) : $el.value"
                class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500">
            @if ($clienteEncontrado)
                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-emerald-500" title="Cliente encontrado">
                    <x-heroicon-o-check-circle class="h-5 w-5" />
                </span>
            @endif
        </div>

        <input type="text" wire:model.blur="nome" placeholder="Nome do cliente"
            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary-500">

        <div x-data="{ open: {{ $enderecoRua || $enderecoBairro ? 'true' : 'false' }} }">
            <button type="button" x-on:click="open = !open"
                class="text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                <span x-show="!open">+ Endereço de entrega</span>
                <span x-show="open">- Endereço de entrega</span>
            </button>

            <div x-show="open" x-cloak class="mt-2 grid grid-cols-6 gap-2">
                <div class="col-span-2">
                    <input type="text" wire:model.blur="enderecoCep" wire:change="buscarPorCep" placeholder="CEP"
                        x-on:input="$el.value = window.maskCep ? window.maskCep($el.value) : $el.value"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                    @if ($cepNaoEncontrado)
                        <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-0.5">CEP não encontrado — preencha manualmente.</p>
                    @endif
                </div>
                <div class="col-span-4">
                    <input type="text" wire:model.blur="enderecoRua" placeholder="Rua"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                <div class="col-span-2">
                    <input type="text" wire:model.blur="enderecoNumero" placeholder="Número"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                <div class="col-span-4">
                    <input type="text" wire:model.blur="enderecoBairro" placeholder="Bairro"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                <div class="col-span-4">
                    <input type="text" wire:model.blur="enderecoCidade" placeholder="Cidade"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">
                </div>
                <div class="col-span-2">
                    <input type="text" wire:model.blur="enderecoUf" placeholder="UF" maxlength="2"
                        class="w-full px-2 py-1.5 text-xs border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 uppercase">
                </div>
            </div>
        </div>
    </div>

    @if ($buscaModalAberta)
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/70 backdrop-blur-sm p-4"
            x-trap.noscroll="true" wire:click.self="$set('buscaModalAberta', false)">
            <div class="w-full sm:max-w-md bg-white dark:bg-gray-800 rounded-t-2xl sm:rounded-2xl p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Buscar cliente</h4>
                    <button type="button" wire:click="$set('buscaModalAberta', false)" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <input type="text" wire:model.live.debounce.300ms="buscaQuery" placeholder="Nome ou telefone..."
                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/10 rounded-lg bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500">

                <div class="max-h-64 overflow-y-auto divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($this->resultadosBusca as $resultado)
                        <button type="button" wire:click="selecionarDaBusca({{ $resultado->id }})"
                            class="w-full text-left px-2 py-2 hover:bg-gray-50 dark:hover:bg-white/5 rounded-lg">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $resultado->cliente_nome }}</p>
                            <p class="text-xs text-gray-400">{{ $resultado->cliente_celular ?? 'sem telefone' }}</p>
                        </button>
                    @empty
                        <p class="text-xs text-gray-400 py-4 text-center">Nenhum resultado.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
