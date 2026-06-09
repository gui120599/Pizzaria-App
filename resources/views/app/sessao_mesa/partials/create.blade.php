<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Abrir nova sessão para ' . $mesa->mesa_nome) }}
        </h2>
        <p class="mt-1 text-sm text-gray-600">
            {{ __('Insira os dados para abrir a sessão da mesa.') }}
        </p>
    </header>

    <form action="{{ route('sessaoMesa.abrir') }}" method="post" class="mt-6 space-y-6">
        @csrf

        {{-- Campos ocultos fixos --}}
        <input type="hidden" name="sessao_mesa_mesa_id" value="{{ $mesa->id }}">
        <input type="hidden" name="sessao_mesa_status" value="ABERTA">
        <input type="hidden" name="sessao_mesa_usuario_id" value="{{ Auth::user()->id }}">

        {{-- Mesa / Garçom (leitura) --}}
        <div class="space-y-3">
            <div>
                <x-input-label value="Mesa" />
                <x-text-input type="text" class="mt-1 w-full" value="{{ $mesa->mesa_nome }}" readonly />
            </div>
            <div>
                <x-input-label value="Garçom" />
                <x-text-input type="text" class="mt-1 w-full" value="{{ Auth::user()->name }}" readonly />
            </div>
        </div>

        {{-- Clientes da sessão --}}
        <div x-data="{
                tel: '',
                clienteId: null,
                nome: '',
                encontrado: false,
                buscando: false,
                clientes: [],
                async buscar() {
                    if (this.tel.replace(/\D/g,'').length < 8) return;
                    this.buscando = true;
                    const r = await fetch('/cardapio/lookup-cliente?telefone=' + encodeURIComponent(this.tel));
                    const d = await r.json();
                    this.buscando = false;
                    if (d.encontrado) { this.clienteId = d.cliente_id; this.nome = d.nome; this.encontrado = true; }
                    else { this.clienteId = null; this.nome = ''; this.encontrado = false; }
                },
                adicionarCliente() {
                    if (!this.nome.trim()) return;
                    this.clientes.push({ id: this.clienteId, nome: this.nome.trim(), tel: this.tel });
                    this.tel = ''; this.clienteId = null; this.nome = ''; this.encontrado = false;
                },
                remover(i) { this.clientes.splice(i, 1); }
             }" class="space-y-3">

            <p class="flex items-center gap-2 text-sm font-bold text-teal-700">
                <i class='bx bx-group'></i> Clientes da mesa
                <span class="text-xs font-normal text-gray-400">(opcional — adicione quantos quiser)</span>
            </p>

            {{-- Chips dos clientes já adicionados --}}
            <template x-if="clientes.length > 0">
                <div class="flex flex-wrap gap-2">
                    <template x-for="(c, i) in clientes" :key="i">
                        <div>
                            <input type="hidden" :name="'clientes_ids[' + i + ']'" :value="c.id ?? ''">
                            <input type="hidden" :name="'clientes_nomes[' + i + ']'" :value="c.nome">
                            <input type="hidden" :name="'clientes_tels[' + i + ']'" :value="c.tel">
                            <span class="inline-flex items-center gap-1.5 bg-teal-50 border border-teal-200 rounded-full px-3 py-1 text-sm text-teal-800 font-medium">
                                <i class='bx bx-user-check text-teal-500 text-xs'></i>
                                <span x-text="c.nome"></span>
                                <button type="button" @click="remover(i)"
                                        class="text-teal-400 hover:text-red-500 transition ml-0.5 leading-none">&times;</button>
                            </span>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Formulário de busca/adicionar --}}
            <div class="bg-gray-50 rounded-xl p-3 space-y-2">
                <div>
                    <x-input-label value="Telefone" />
                    <div class="flex gap-2 mt-1">
                        <input type="tel" x-model="tel" @input.debounce.500ms="buscar()"
                               placeholder="(00) 00000-0000"
                               class="flex-1 border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                        <button type="button" @click="tel=''; clienteId=null; nome=''; encontrado=false;" x-show="tel"
                                class="px-3 bg-gray-100 hover:bg-red-50 text-gray-500 rounded-lg text-xs transition-colors">✕</button>
                    </div>
                </div>

                <div x-show="buscando" class="text-xs text-gray-400">Buscando...</div>

                <div x-show="encontrado && !buscando" class="flex items-center gap-2 px-2 py-1.5 bg-green-50 border border-green-200 rounded-lg">
                    <i class='bx bx-user-check text-green-600 text-sm'></i>
                    <span class="text-sm text-green-700 font-medium" x-text="nome"></span>
                </div>

                <div x-show="!encontrado && !buscando && tel.replace(/\D/g,'').length >= 8">
                    <x-input-label value="Nome (novo cliente)" />
                    <input type="text" x-model="nome" placeholder="Nome completo"
                           class="mt-1 w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                </div>

                <button type="button" @click="adicionarCliente()"
                        x-show="nome.trim()"
                        class="w-full py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-semibold transition-colors">
                    <i class='bx bx-plus mr-1'></i> Adicionar cliente
                </button>
            </div>
        </div>

        <x-primary-button>
            {{ __('Abrir Sessão ' . $mesa->mesa_nome) }}
        </x-primary-button>
    </form>
</section>
