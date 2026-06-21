<section class="h-full space-y-2">
    <div class="overflow-auto mx-auto md:h-[5%] ">
        <div class="flex flex-col md:flex-row justify-between p-3 md:p-0 space-y-2 md:space-y-0">
            <div class="flex md:hidden space-x-2 items-center">
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('Pedidos e Itens da ' . $mesa->mesa_nome) }}
                </h2>
            </div>
            <div class="flex space-x-2">
                <x-primary-link
                    href="{{ route('sessaoMesa.pedidoMesa', ['mesa_id' => $sessao_mesa->sessao_mesa_mesa_id]) }}"><i
                        class='bx bx-plus'></i>NOVO PEDIDO</x-primary-link>
                @php
                    $openPedidosExistentes = request('page_PedidosExistentes') > 0;
                    $openRemoverPedidos = request('page_RemoverPedidos') > 0;
                @endphp

                <x-secondary-button x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'seleciona-pedido')"><i class='bx bxs-plus-circle'></i>
                    {{ __('Adicionar pedido existente') }}</x-secondary-button>

                <x-danger-button x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'remover-pedido')"><i class='bx bx-minus-circle'></i>
                    {{ __('Remover pedido da mesa') }}</x-danger-button>

                <x-secondary-button x-data=""
                    x-on:click.prevent="$dispatch('open-modal', 'gerenciar-clientes')">
                    <i class='bx bx-group'></i>
                    Clientes
                    @if(count($sessaoMesaClientes) > 0)
                        <span class="ml-1 bg-teal-600 text-white text-[10px] font-bold rounded-full w-4 h-4 flex items-center justify-center">{{ count($sessaoMesaClientes) }}</span>
                    @endif
                </x-secondary-button>

                {{-- Modal: Gerenciar Clientes da Sessão --}}
                <x-modal name="gerenciar-clientes" :show="false" :maxWidth="'md'">
                    <div class="p-6 space-y-4"
                         x-data="{
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
                             adicionar() {
                                 if (!this.nome.trim()) return;
                                 this.clientes.push({ id: this.clienteId, nome: this.nome.trim(), tel: this.tel });
                                 this.tel = ''; this.clienteId = null; this.nome = ''; this.encontrado = false;
                             },
                             remover(i) { this.clientes.splice(i, 1); },
                             @include('app.sessao_mesa.partials._busca_cliente_nome_js')
                         }">

                        {{-- Header --}}
                        <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                            <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                                <i class='bx bx-group text-teal-600'></i> Clientes da {{ $mesa->mesa_nome }}
                            </h2>
                            <button x-on:click="$dispatch('close')" type="button"
                                    class="text-gray-400 hover:text-gray-600 transition-colors">
                                <i class='bx bx-x text-xl'></i>
                            </button>
                        </div>

                        {{-- Clientes já registrados na sessão --}}
                        @if (count($sessaoMesaClientes) > 0)
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                                    Já registrados
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($sessaoMesaClientes as $c)
                                        <form method="POST"
                                              action="{{ route('sessaoMesa.removerCliente', [$sessao_mesa, $c['smc_id']]) }}"
                                              onsubmit="return confirm('Remover {{ addslashes($c['nome']) }} da sessão?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="inline-flex items-center gap-1.5 bg-teal-50 border border-teal-200 hover:border-red-300 hover:bg-red-50 rounded-full px-3 py-1 text-sm text-teal-800 hover:text-red-600 font-medium transition-colors group">
                                                <i class='bx bx-user-check text-teal-500 group-hover:text-red-400 text-xs'></i>
                                                {{ $c['nome'] }}
                                                <i class='bx bx-x text-xs opacity-0 group-hover:opacity-100 transition-opacity'></i>
                                            </button>
                                        </form>
                                    @endforeach
                                </div>
                                <p class="text-[10px] text-gray-400 mt-1.5">Clique num cliente para removê-lo da sessão</p>
                            </div>
                        @else
                            <p class="text-sm text-gray-400 text-center py-2">Nenhum cliente registrado nesta sessão.</p>
                        @endif

                        {{-- Form para adicionar novos --}}
                        <form action="{{ route('sessaoMesa.adicionarClientes', $sessao_mesa) }}" method="POST"
                              class="space-y-3 border-t border-gray-100 pt-4">
                            @csrf

                            {{-- Chips dos novos clientes a adicionar --}}
                            <template x-if="clientes.length > 0">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">A adicionar</p>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="(c, i) in clientes" :key="i">
                                            <div>
                                                <input type="hidden" :name="'clientes_ids[' + i + ']'" :value="c.id ?? ''">
                                                <input type="hidden" :name="'clientes_nomes[' + i + ']'" :value="c.nome">
                                                <input type="hidden" :name="'clientes_tels[' + i + ']'" :value="c.tel">
                                                <span class="inline-flex items-center gap-1.5 bg-indigo-50 border border-indigo-200 rounded-full px-3 py-1 text-sm text-indigo-800 font-medium">
                                                    <i class='bx bx-user-plus text-indigo-400 text-xs'></i>
                                                    <span x-text="c.nome"></span>
                                                    <button type="button" @click="remover(i)"
                                                            class="text-indigo-400 hover:text-red-500 transition-colors ml-0.5 leading-none">&times;</button>
                                                </span>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Busca por telefone --}}
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700">Telefone</label>
                                    @include('app.sessao_mesa.partials._busca_cliente_nome')
                                </div>
                                <div class="flex gap-2">
                                    <input type="tel" x-model="tel" @input.debounce.500ms="buscar()"
                                           placeholder="(00) 00000-0000"
                                           class="flex-1 border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                                    <button type="button" @click="tel=''; clienteId=null; nome=''; encontrado=false;" x-show="tel"
                                            class="px-3 bg-gray-100 hover:bg-red-50 text-gray-500 rounded-lg text-xs transition-colors">✕</button>
                                </div>
                            </div>

                            <div x-show="buscando" class="text-xs text-gray-400">Buscando...</div>

                            <div x-show="encontrado && !buscando"
                                 class="flex items-center gap-2 px-3 py-2 bg-green-50 border border-green-200 rounded-lg">
                                <i class='bx bx-user-check text-green-600'></i>
                                <span class="text-sm text-green-700 font-medium" x-text="nome"></span>
                            </div>

                            <div x-show="!encontrado && !buscando && tel.replace(/\D/g,'').length >= 8">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nome (novo cliente)</label>
                                <input type="text" x-model="nome" placeholder="Nome completo"
                                       class="w-full border-gray-300 rounded-lg shadow-sm text-sm focus:ring-teal-500 focus:border-teal-500">
                            </div>

                            <button type="button" @click="adicionar()" x-show="nome.trim()"
                                    class="w-full py-2 bg-teal-50 hover:bg-teal-100 border border-teal-300 text-teal-700 rounded-lg text-sm font-semibold transition-colors">
                                <i class='bx bx-plus mr-1'></i> Adicionar à lista
                            </button>

                            {{-- Botões de ação --}}
                            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                                <x-secondary-button x-on:click="$dispatch('close')" type="button">
                                    Fechar
                                </x-secondary-button>
                                <button type="submit" x-show="clientes.length > 0"
                                        class="inline-flex items-center gap-1 px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-bold transition-colors shadow-sm">
                                    <i class='bx bx-user-plus'></i>
                                    Confirmar (<span x-text="clientes.length"></span>)
                                </button>
                            </div>
                        </form>
                    </div>
                </x-modal>

                <x-modal name="seleciona-pedido" :show="$openPedidosExistentes" :maxWidth="'6xl'">

                    <form method="POST"
                        action="{{ route('sessaoMesa.updateAdicionarExistentes', ['sessaoMesa' => $sessao_mesa]) }}"
                        class="p-6">
                        <div class="border-b-2 flex justify-between mb-2 p-2">
                            <div class="">
                                <h2 class="text-lg font-medium text-gray-900">
                                    {{ __('Pedidos disponíveis') }}
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    {{ __('Lista de pedidos que estão com status ABERTO, PREPARANDO, PRONTO ou ENTREGUE.') }}
                                </p>
                            </div>
                            <x-secondary-button x-on:click="$dispatch('close')">
                                <i class='bx bx-x text-lg'></i>
                            </x-secondary-button>
                        </div>

                        @csrf
                        @method('patch')
                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 pe-3 py-3 h-[70vh] overflow-auto">

                            @foreach ($pedidosExistentes as $pedido)
                                <div
                                    class="col-span-1 border-2 p-1 rounded-lg w-full shadow-sm flex flex-col justify-between">
                                    <div>
                                        <div class="space-y-1">
                                            <div class="grid grid-cols-3 space-x-1">
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <div
                                                        class="flex items-center justify-center space-x-2 w-full text-center text-sm rounded-lg p-1 font-bold">
                                                        <input id="pedido-{{ $pedido->id }}" name="pedidoExistente[]"
                                                            type="checkbox" value="{{ $pedido->id }}"
                                                            class="peer hidden">
                                                        <label for="pedido-{{ $pedido->id }}"
                                                            class="flex-1 p-1 rounded-lg border-2  peer-checked:bg-gradient-to-r peer-checked:from-green-400 peer-checked:to-green-600 text-gray-900 cursor-pointer">
                                                            PEDIDO: {{ $pedido->id }}
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase">STATUS</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">
                                                        {{ $pedido->pedido_status }}
                                                    </span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase"><i class='bx bx-calendar'></i>
                                                        Data/Hora</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">{{ \Carbon\Carbon::parse($pedido->pedido_datahora_abertura)->format('d/m/y H:i') }}</span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px]">Garçom</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->garcom->name_first }}</span>
                                                </div>
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <span class="text-[7px]">Mesa</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="itens-pedido">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <div id="Tabela">
                                                <table class="w-full">
                                                    <thead>
                                                        <tr class="text-[8px] bg-emerald-500 text-white rounded-lg p-1">
                                                            <th>QTD</th>
                                                            <th class="max-w-max">PRODUTO</th>
                                                            <th>VALOR</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($pedido->produtosInseridosPedido as $item)
                                                            @if ($item->pivot->item_pedido_observacao)
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }} -
                                                                        <span
                                                                            class="font-bold">{{ $item->pivot->item_pedido_observacao }}</span>
                                                                    </td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @else
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }}</td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @endif
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    @if ($pedido->pedido_venda_id)
                                        <div class="flex items-center">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <span class="text-center">Pedido Já Pago! Venda:
                                                {{ $pedido->pedido_venda_id }}</span>
                                        </div>
                                    @endif

                                    <div>
                                        <hr class="h-px my-1 border-0 bg-gray-200">
                                        <div class="flex justify-between">
                                            <span>Valor do Pedido</span>
                                            <span class="font-bold">R$
                                                {{ str_replace('.', ',', $pedido->pedido_valor_total) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6 flex justify-between">
                            <div class="">
                                {{ $pedidosExistentes->links() }}
                            </div>
                            <div>
                                <x-secondary-button x-on:click="$dispatch('close')">
                                    {{ __('Cancel') }}
                                </x-secondary-button>

                                <x-primary-button class="ms-3">
                                    {{ __('Adicionar pedidos selecionados') }}
                                </x-primary-button>
                            </div>
                        </div>
                    </form>
                </x-modal>
                <x-modal name="remover-pedido" :show="$openRemoverPedidos" :maxWidth="'6xl'">

                    <form method="POST"
                        action="{{ route('sessaoMesa.updateRemoverPedidosSessaoMesa', ['sessaoMesa' => $sessao_mesa]) }}"
                        class="p-6">
                        <div class="border-b-2 flex justify-between mb-2 p-2">
                            <div class="">
                                <h2 class="text-lg font-medium text-gray-900">
                                    {{ __('Pedidos disponíveis') }}
                                </h2>

                                <p class="mt-1 text-sm text-gray-600">
                                    {{ __('Lista de pedidos que estão na ' . $mesa->mesa_nome . ', e podem ser removidos.') }}
                                </p>
                            </div>
                            <x-secondary-button x-on:click="$dispatch('close')">
                                <i class='bx bx-x text-lg'></i>
                            </x-secondary-button>
                        </div>

                        @csrf
                        @method('patch')
                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 pe-3 py-3 h-[70vh] overflow-auto">

                            @foreach ($pedidosRemover as $pedido)
                                <div
                                    class="col-span-1 border-2 p-1 rounded-lg w-full shadow-sm flex flex-col justify-between">
                                    <div>
                                        <div class="space-y-1">
                                            <div class="grid grid-cols-3 space-x-1">
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <div
                                                        class="flex items-center justify-center space-x-2 w-full text-center text-sm rounded-lg p-1 font-bold">
                                                        <input id="pedido-{{ $pedido->id }}" name="pedidoExistente[]"
                                                            type="checkbox" value="{{ $pedido->id }}"
                                                            class="peer hidden">
                                                        <label for="pedido-{{ $pedido->id }}"
                                                            class="flex-1 p-1 rounded-lg border-2  peer-checked:bg-gradient-to-r peer-checked:from-green-400 peer-checked:to-green-600 text-gray-900 cursor-pointer">
                                                            PEDIDO: {{ $pedido->id }}
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase">STATUS</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">
                                                        {{ $pedido->pedido_status }}
                                                    </span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px] uppercase"><i class='bx bx-calendar'></i>
                                                        Data/Hora</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">{{ \Carbon\Carbon::parse($pedido->pedido_datahora_abertura)->format('d/m/y H:i') }}</span>
                                                </div>
                                                <div class="col-span-1 flex flex-col items-center">
                                                    <span class="text-[7px]">Garçom</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->garcom->name_first }}</span>
                                                </div>
                                                <div class="col-span-3 flex flex-col items-center">
                                                    <span class="text-[7px]">Mesa</span>
                                                    <span
                                                        class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="itens-pedido">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <div id="Tabela">
                                                <table class="w-full">
                                                    <thead>
                                                        <tr
                                                            class="text-[8px] bg-emerald-500 text-white rounded-lg p-1">
                                                            <th>QTD</th>
                                                            <th class="max-w-max">PRODUTO</th>
                                                            <th>VALOR</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($pedido->produtosInseridosPedido as $item)
                                                            @if ($item->pivot->item_pedido_observacao)
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }} -
                                                                        <span
                                                                            class="font-bold">{{ $item->pivot->item_pedido_observacao }}</span>
                                                                    </td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @else
                                                                <tr>
                                                                    <td class="text-xs font-bold text-center">
                                                                        {{ $item->pivot->item_pedido_quantidade }}</td>
                                                                    <td class="text-xs text-center uppercase">
                                                                        {{ $item->categoria->categoria_nome }}
                                                                        {{ $item->produto_descricao }}</td>
                                                                    <td class="text-xs font-bold text-right">R$
                                                                        {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                                    </td>
                                                                </tr>
                                                            @endif
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    @if ($pedido->pedido_venda_id)
                                        <div class="flex items-center">
                                            <hr class="h-px my-1 border-0 bg-gray-200">
                                            <span class="text-center">Pedido Já Pago! Venda:
                                                {{ $pedido->pedido_venda_id }}</span>
                                        </div>
                                    @endif

                                    <div>
                                        <hr class="h-px my-1 border-0 bg-gray-200">
                                        <div class="flex justify-between">
                                            <span>Valor do Pedido</span>
                                            <span class="font-bold">R$
                                                {{ str_replace('.', ',', $pedido->pedido_valor_total) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6 flex justify-between">
                            <div class="">
                                {{ $pedidosRemover->links() }}
                            </div>
                            <div>
                                <x-secondary-button x-on:click="$dispatch('close')">
                                    {{ __('Cancel') }}
                                </x-secondary-button>

                                <x-danger-button class="ms-3">
                                    {{ __('Remover pedidos selecionados') }}
                                </x-danger-button>
                            </div>
                        </div>
                    </form>
                </x-modal>
            </div>
            <div class="hidden md:flex space-x-2">
                <h2 class="text-lg font-medium text-gray-900">
                    {{ __('Pedidos e Itens da ' . $mesa->mesa_nome) }}
                </h2>
            </div>
            <div class="flex space-x-2">
                <x-primary-link
                    href="{{ route('sessaoMesa.editAlterarMesa', ['sessaoMesa' => $sessao_mesa]) }}">alterar
                    mesa</x-primary-link>
                <x-secondary-button id="btn-imprimir">IMPRIMIR</x-secondary-button>
                <form action="{{ route('sessaoMesa.fechar', ['sessaoMesa' => $sessao_mesa]) }}" method="get">
                    @method('patch')
                    <x-primary-button>FECHAR MESA</x-primary-button>
                </form>
            </div>
        </div>
    </div>
    <div class="overflow-auto w-full md:mx-auto md:h-[95%] flex flex-col">
        {{-- VALORES --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 space-y-2 md:space-y-0 lg:space-x-2">
            {{-- TOTAL --}}
            <div class="col-span-1 border rounded-xl shadow-lg p-2 bg-gradient-to-l from-green-500 to-amber-100">
                <div class="grid grid-cols-3">
                    <div class="col-span-1 flex flex-col items-start md:items-end">
                        <span class="text-[9px] md:text-base font-bold">VALOR TOTAL</span>
                        <span class="text-2xl text-gray-400">R$</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center">
                        <span
                            class="text-4xl font-bold text-end">{{ number_format($pedidos->sum('pedido_valor_total'), 2, ',', '.') }}</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center max-h-5 object-cover">
                        <i class='bx bx-dollar text-6xl md:text-9xl text-gray-200'></i>
                    </div>
                </div>
            </div>
            {{-- ITENS --}}
            <div class="col-span-1 border rounded-xl shadow-lg p-2 bg-gradient-to-l from-amber-500 to-amber-100">
                <div class="grid grid-cols-3">
                    <div class="col-span-1 flex flex-col items-start md:items-end">
                        <span class="text-[9px] md:text-base font-bold">VALOR ITENS</span>
                        <span class="text-2xl text-gray-400">R$</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center">
                        <span
                            class="text-4xl font-bold text-end">{{ number_format($pedidos->sum('pedido_valor_itens'), 2, ',', '.') }}</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center max-h-5 object-cover">
                        <i class='bx bxs-pizza text-6xl md:text-9xl text-gray-200'></i>
                    </div>
                </div>
            </div>
            {{-- DESCONTO --}}
            <div class="col-span-1 border rounded-xl shadow-lg p-2 bg-gradient-to-l from-blue-500 to-amber-100">
                <div class="grid grid-cols-3">
                    <div class="col-span-1 flex flex-col items-start md:items-end">
                        <span class="text-[9px] md:text-base font-bold">VALOR DESCONTO</span>
                        <span class="text-2xl text-gray-400">R$</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center">
                        <span
                            class="text-4xl font-bold text-end">{{ number_format($pedidos->sum('pedido_valor_desconto'), 2, ',', '.') }}</span>
                    </div>
                    <div class="col-span-1 flex justify-center items-center max-h-5 object-cover">
                        <i class='bx bxs-badge-dollar text-6xl md:text-9xl text-gray-200 '></i>
                    </div>
                </div>
            </div>
        </div>
        {{-- PEDIDOS --}}
        <div class="overflow-auto">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2 pe-3 py-3">
                @foreach ($pedidos as $pedido)
                    <div class="col-span-1 border-2 p-1 rounded-lg w-full shadow-sm flex flex-col justify-between">
                        <div>
                            <div class="space-y-1">
                                <div class="grid grid-cols-3 space-x-1">
                                    <div class="col-span-3 flex flex-col items-center">
                                        <span
                                            class="w-full text-center text-sm bg-gradient-to-r to-teal-600 from-teal-400 rounded-lg p-1 text-white font-bold">PEDIDO:
                                            {{ $pedido->id }}</span>
                                    </div>
                                    <div class="col-span-1 flex flex-col items-center">
                                        <span class="text-[7px] uppercase">STATUS</span>
                                        <span
                                            class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">
                                            {{ $pedido->pedido_status }}
                                        </span>
                                    </div>
                                    <div class="col-span-1 flex flex-col items-center">
                                        <span class="text-[7px] uppercase"><i class='bx bx-calendar'></i>
                                            Data/Hora</span>
                                        <span
                                            class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full">{{ \Carbon\Carbon::parse($pedido->pedido_datahora_abertura)->format('d/m/y H:i') }}</span>
                                    </div>
                                    <div class="col-span-1 flex flex-col items-center">
                                        <span class="text-[7px]">Garçom</span>
                                        <span
                                            class="w-full flex items-center justify-center text-center text-sm bg-gray-700 rounded-lg p-1 text-white h-full uppercase">{{ $pedido->garcom->name_first }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="itens-pedido">
                                <hr class="h-px my-1 border-0 bg-gray-200">
                                <div id="Tabela">
                                    <table class="w-full">
                                        <thead>
                                            <tr class="text-[8px] bg-emerald-500 text-white rounded-lg p-1">
                                                <th>QTD</th>
                                                <th class="max-w-max">PRODUTO</th>
                                                <th>VALOR</th>
                                                <th>EXCLUIR</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($pedido->produtosInseridosPedido as $item)
                                                @if ($item->pivot->item_pedido_observacao)
                                                    <tr>
                                                        <td class="text-xs font-bold text-center">
                                                            {{ $item->pivot->item_pedido_quantidade }}</td>
                                                        <td class="text-xs text-center uppercase">
                                                            {{ $item->categoria->categoria_nome }}
                                                            {{ $item->produto_descricao }} -
                                                            <span
                                                                class="font-bold">{{ $item->pivot->item_pedido_observacao }}</span>
                                                        </td>
                                                        <td class="text-xs font-bold text-right">R$
                                                            {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                        </td>
                                                        <td>
                                                            <form
                                                                action="{{ route('removerItemPedidoMesa', ['item_pedido_id' => $item->pivot->id, 'pedido_id' => $pedido->id]) }}"
                                                                method="GET"
                                                                onsubmit="return confirm('Tem certeza que deseja remover este item?');">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="bg-white font-bold text-xl p-1 w-full"
                                                                    title="Excluir Item"><i
                                                                        class='text-red-500 bx bxs-x-circle'></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @else
                                                    <tr>
                                                        <td class="text-xs font-bold text-center">
                                                            {{ $item->pivot->item_pedido_quantidade }}</td>
                                                        <td class="text-xs text-center uppercase">
                                                            {{ $item->categoria->categoria_nome }}
                                                            {{ $item->produto_descricao }}</td>
                                                        <td class="text-xs font-bold text-right">R$
                                                            {{ str_replace('.', ',', $item->pivot->item_pedido_valor) }}
                                                        </td>
                                                        <td class="text-center p-1">
                                                            <form
                                                                action="{{ route('removerItemPedidoMesa', ['item_pedido_id' => $item->pivot->id, 'pedido_id' => $pedido->id]) }}"
                                                                method="GET"
                                                                onsubmit="return confirm('Tem certeza que deseja remover este item?');">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="bg-white font-bold text-xl p-1 w-full"
                                                                    title="Excluir Item"><i
                                                                        class='text-red-500 bx bxs-x-circle'></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        @if ($pedido->pedido_venda_id)
                            <div class="flex items-center">
                                <hr class="h-px my-1 border-0 bg-gray-200">
                                <span class="text-center">Pedido Já Pago! Venda: {{ $pedido->pedido_venda_id }}</span>
                            </div>
                        @endif

                        <div>
                            <hr class="h-px my-1 border-0 bg-gray-200">
                            <div class="flex justify-between items-center">
                                <span>Valor do Pedido</span>
                                <span class="font-bold">R$
                                    {{ str_replace('.', ',', $pedido->pedido_valor_total) }}</span>
                            </div>
                            @if (!in_array($pedido->pedido_status, ['INICIADO', 'ENTREGUE', 'FINALIZADO', 'CANCELADO']))
                                <a href="{{ route('sessaoMesa.editarPedido', ['mesa_id' => $mesa->id, 'pedido' => $pedido->id]) }}"
                                   class="mt-1.5 flex items-center justify-center gap-1 w-full py-1.5 bg-teal-50 hover:bg-teal-100 border border-teal-300 text-teal-700 rounded-lg text-xs font-semibold transition-colors">
                                    <i class='bx bx-edit-alt'></i> Editar pedido
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <script type="module">
        $("#btn-imprimir").click(function(e) {
            e.preventDefault();
            window.open('{{ route('sessaoMesa.imprimir', ['id' => $sessao_mesa->id]) }}', 'Teste',
                'width=600,height=400');
        });
    </script>
</section>
