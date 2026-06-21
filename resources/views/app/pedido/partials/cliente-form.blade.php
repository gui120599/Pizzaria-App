{{-- Bloco Cliente: busca por telefone + modal de busca por nome.
     Espera a variável $pedido (pode ter cliente já vinculado para prefill). --}}
<div class="bg-white shadow-sm rounded-xl p-4 space-y-3"
     x-data="{
         tel: '{{ addslashes($pedido->cliente?->cliente_celular ?? '') }}',
         clienteId: {{ $pedido->pedido_cliente_id ?? 'null' }},
         nome: '{{ addslashes($pedido->cliente?->cliente_nome ?? '') }}',
         encontrado: {{ $pedido->pedido_cliente_id ? 'true' : 'false' }},
         buscando: false,
         modalAberta: false,
         termo: '',
         resultados: [],
         buscandoNome: false,
         async buscar() {
             if (this.tel.replace(/\D/g,'').length < 8) return;
             this.buscando = true;
             const r = await fetch('/cardapio/lookup-cliente?telefone=' + encodeURIComponent(this.tel));
             const d = await r.json();
             this.buscando = false;
             if (d.encontrado) { this.clienteId = d.cliente_id; this.nome = d.nome; this.encontrado = true; }
             else { this.clienteId = null; this.nome = ''; this.encontrado = false; }
         },
         async buscarPorNome() {
             if (this.termo.trim().length < 2) { this.resultados = []; return; }
             this.buscandoNome = true;
             const r = await fetch('{{ route('cliente.buscar_nome') }}?nome=' + encodeURIComponent(this.termo));
             const d = await r.json();
             this.buscandoNome = false;
             this.resultados = d.clientes ?? [];
         },
         abrirModal() { this.modalAberta = true; this.termo = ''; this.resultados = []; $nextTick(() => $refs.inputNome?.focus()); },
         fecharModal() { this.modalAberta = false; },
         selecionar(c) {
             this.clienteId = c.cliente_id;
             this.nome = c.nome;
             this.tel = c.celular ?? '';
             this.encontrado = true;
             this.fecharModal();
         },
         limpar() { this.tel = ''; this.clienteId = null; this.nome = ''; this.encontrado = false; }
     }"
     @keydown.escape.window="modalAberta = false">
    <div class="flex items-center justify-between">
        <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
            <i class='bx bx-user'></i> Cliente
        </p>
        <button type="button" @click="abrirModal()"
                class="flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-teal-700 bg-teal-50 hover:bg-teal-100 rounded-lg transition-colors">
            <i class='bx bx-search-alt'></i> Buscar por nome
        </button>
    </div>
    <input type="hidden" name="pedido_cliente_id" :value="clienteId">
    <input type="hidden" name="cliente_celular_novo" :value="tel">
    <input type="hidden" name="cliente_nome_novo" :value="nome">

    <div>
        <x-input-label value="Telefone" />
        <div class="flex gap-2 mt-1">
            <input type="tel" x-model="tel" @input.debounce.500ms="buscar()"
                   placeholder="(00) 00000-0000"
                   class="flex-1 border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
            <button type="button" @click="limpar()" x-show="tel"
                    class="px-3 py-2 bg-gray-100 hover:bg-red-50 text-gray-500 rounded-lg text-xs transition-colors">✕</button>
        </div>
    </div>

    <div x-show="buscando" class="text-xs text-gray-400">Buscando...</div>

    <div x-show="encontrado && !buscando" class="flex items-center gap-2 p-2 bg-green-50 border border-green-200 rounded-lg">
        <i class='bx bx-user-check text-green-600'></i>
        <span class="text-sm text-green-700 font-medium" x-text="nome"></span>
    </div>

    <div x-show="!encontrado && !buscando && tel.replace(/\D/g,'').length >= 8">
        <x-input-label value="Nome do cliente (novo)" />
        <input type="text" x-model="nome" placeholder="Nome completo"
               class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
    </div>

    {{-- Modal de busca por nome --}}
    <template x-teleport="body">
        <div x-show="modalAberta" x-cloak
             class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:pt-24"
             style="display:none">
            <div class="absolute inset-0 bg-black/40" @click="fecharModal()"></div>
            <div class="relative bg-white w-full max-w-md rounded-2xl shadow-xl flex flex-col max-h-[80vh]"
                 x-transition.origin.top>
                <div class="flex items-center justify-between p-4 border-b border-gray-100">
                    <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                        <i class='bx bx-search-alt'></i> Buscar cliente por nome
                    </p>
                    <button type="button" @click="fecharModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">✕</button>
                </div>
                <div class="p-4">
                    <input type="text" x-ref="inputNome" x-model="termo" @input.debounce.400ms="buscarPorNome()"
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
                                <button type="button" @click="selecionar(c)"
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
</div>
