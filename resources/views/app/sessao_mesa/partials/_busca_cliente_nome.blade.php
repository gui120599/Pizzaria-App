{{-- Botão + modal de busca de cliente por nome para sessão de mesa.
     Deve ser incluído DENTRO de um x-data que tenha: tel, clienteId, nome,
     encontrado e as chaves de busca abaixo (modalBuscaAberta, termo,
     resultados, buscandoNome, abrirBusca, buscarPorNome, selecionarBusca). --}}
<button type="button" @click="abrirBusca()"
        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-teal-700 bg-teal-50 hover:bg-teal-100 rounded-lg transition-colors">
    <i class='bx bx-search-alt'></i> Buscar por nome
</button>

<template x-teleport="body">
    <div x-show="modalBuscaAberta" x-cloak
         @keydown.escape.window="modalBuscaAberta = false"
         class="fixed inset-0 z-[60] flex items-start justify-center p-4 sm:pt-24"
         style="display:none">
        <div class="absolute inset-0 bg-black/40" @click="modalBuscaAberta = false"></div>
        <div class="relative bg-white w-full max-w-md rounded-2xl shadow-xl flex flex-col max-h-[80vh]"
             x-transition>
            <div class="flex items-center justify-between p-4 border-b border-gray-100">
                <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                    <i class='bx bx-search-alt'></i> Buscar cliente por nome
                </p>
                <button type="button" @click="modalBuscaAberta = false" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
            </div>
            <div class="p-4">
                <input type="text" id="input-busca-nome-mesa" x-model="termo" @input.debounce.400ms="buscarPorNome()"
                       placeholder="Digite o nome do cliente..."
                       class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
            </div>
            <div class="px-4 pb-4 overflow-y-auto">
                <div x-show="buscandoNome" class="text-xs text-gray-400 py-2">Buscando...</div>
                <div x-show="!buscandoNome && termo.trim().length >= 2 && resultados.length === 0"
                     class="text-sm text-gray-400 py-4 text-center">Nenhum cliente encontrado.</div>
                <ul class="divide-y divide-gray-100">
                    <template x-for="c in resultados" :key="c.cliente_id">
                        <li>
                            <button type="button" @click="selecionarBusca(c)"
                                    class="w-full text-left py-2.5 px-2 hover:bg-teal-50 rounded-lg transition-colors">
                                <p class="text-sm font-medium text-gray-800" x-text="c.nome"></p>
                                <p class="text-xs text-gray-500 flex items-center gap-2 flex-wrap">
                                    <span x-show="c.celular" class="flex items-center gap-1"><i class='bx bx-phone'></i><span x-text="c.celular"></span></span>
                                    <span x-show="c.endereco" class="flex items-center gap-1"><i class='bx bx-map'></i><span x-text="c.endereco"></span></span>
                                </p>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
</template>
