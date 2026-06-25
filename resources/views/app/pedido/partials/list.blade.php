<section class="h-full">
    <div class="w-[18rem] sm:w-[99%] overflow-auto h-full">
        <form action="{{ route('pedidos') }}" method="GET">
            <x-text-input name="search" id="search" placeholder="Pesquisar Código"></x-text-input>
            <x-primary-button>Pesquisar</x-primary-button>
        </form>
        <table class="w-full text-[7px] md:text-base">
            <thead class="sticky top-0 text-start bg-white ">
                <tr class="border-b-4">
                    <th class="">#</th>
                    <th class="">Data/Hora</th>
                    <th class="">STATUS</th>
                    <th class="text-start max-w-max">Cliente</th>
                    <th class="text-start">Mesa</th>
                    <th class="text-start">Garçom</th>
                    <th class="text-start">Origem</th>
                    <th class="text-start">ENTREGA</th>
                    <th class="text-start">Valor total</th>
                    <th class="text-start max-w-max">Opções</th>
                </tr>
            </thead>
            <tbody>
                @if (count($pedidos) > 0)
                    @foreach ($pedidos as $pedido)
                        <tr class="border-b-2 border-gray-100">
                            <td class="text-center">{{ $pedido->id }}</td>
                            <td class="text-center">{{ $pedido->pedido_datahora_abertura?->format('d/m/Y H:i') ?? $pedido->created_at->format('d/m/Y H:i') }}</td>
                            <td class="text-center">{{ $pedido->pedido_status }}</td>
                            <td class="">{{ $pedido->cliente?->cliente_nome ?? '—' }}</td>
                            <td class="">{{ $pedido->sessaoMesa?->mesa?->mesa_nome ?? '—' }}</td>
                            <td class="text-start">{{ $pedido->garcom?->name_first ?? '—' }}</td>
                            <td class="text-start">
                                @php
                                    $origemClasse = match($pedido->pedido_origem?->value) {
                                        'cardapio'  => 'bg-green-100 text-green-700',
                                        'mesa'      => 'bg-amber-100 text-amber-700',
                                        'atendente' => 'bg-blue-100 text-blue-700',
                                        default     => 'bg-gray-100 text-gray-600',
                                    };
                                @endphp
                                <span class="px-2 py-0.5 rounded-full text-[10px] md:text-xs font-bold whitespace-nowrap {{ $origemClasse }}">
                                    {{ $pedido->pedido_origem?->label() ?? '—' }}
                                </span>
                            </td>
                            <td class="text-start">
                                <span>{{ $pedido->opcaoEntrega?->opcaoentrega_nome ?? '—' }}</span>
                            </td>
                            <td class="text-start">R$ {{ number_format($pedido->item_pedido_pedido_id->sum('item_pedido_valor') - $pedido->pedido_valor_desconto, 2, ',', '.') }}</td>

                            @php
                                $nomeOpcaoPedido = strtolower($pedido->opcaoEntrega?->opcaoentrega_nome ?? '');
                                $isEntregaOuRetirada = str_contains($nomeOpcaoPedido, 'entrega')
                                    || str_contains($nomeOpcaoPedido, 'deliver')
                                    || str_contains($nomeOpcaoPedido, 'retirada');
                            @endphp
                            @if ($pedido->pedido_status != 'CANCELADO')
                                <td class="text-center inline-flex gap-x-2">
                                    <x-secondary-button
                                        onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Teste', 'width=600,height=400' );"
                                        title="IMPRIMIR"><i class='bx bx-printer'></i></x-secondary-button>
                                    <x-secondary-button title="MOVIMENTAÇÃO DO PEDIDO" x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'mov-pedido-{{ $pedido->id }}')"><i
                                            class='bx bx-transfer'></i></x-secondary-button>

                                    <a href="{{ route('pedido.editar', $pedido->id) }}"
                                       title="EDITAR PEDIDO COMPLETO"
                                       class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-teal-50 hover:border-teal-400 hover:text-teal-700 focus:outline-none transition ease-in-out duration-150">
                                        <i class='bx bxs-edit'></i>
                                    </a>

                                    <x-secondary-button title="ALTERAR ENTREGA" x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'alterar-pedido-{{ $pedido->id }}')"><i
                                            class='bx bxs-edit-alt'></i></x-secondary-button>

                                    @if ($isEntregaOuRetirada)
                                        <button type="button"
                                            title="COPIAR LINK DE ACOMPANHAMENTO"
                                            onclick="copiarLinkAcompanhamento('{{ route('pedido.acompanhar', $pedido->id) }}', this)"
                                            class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-teal-700 uppercase tracking-widest shadow-sm hover:bg-teal-50 hover:border-teal-400 focus:outline-none transition ease-in-out duration-150">
                                            <i class='bx bx-link'></i>
                                        </button>
                                    @endif

                                    <x-danger-button type="button" title="CANCELAR"
                                        x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'cancelar-pedido-{{ $pedido->id }}')">
                                        <i class='bx bx-trash'></i>
                                    </x-danger-button>
                                </td>
                            @else
                                <td class="text-center inline-flex gap-x-2">
                                    <x-secondary-button
                                        onclick="window.open('{{ route('pedido.imprimir', ['id' => $pedido->id]) }}', 'Teste', 'width=600,height=400' );"
                                        title="IMPRIMIR"><i class='bx bx-printer'></i></x-secondary-button>

                                    <x-secondary-button x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'mov-pedido-{{ $pedido->id }}')"
                                        title="ALTERAR DO PEDIDO"><i class='bx bx-transfer'></i></x-secondary-button>

                                    <x-secondary-button title="ALTERAR DO PEDIDO" x-data=""
                                        x-on:click.prevent="$dispatch('open-modal', 'alterar-pedido-{{ $pedido->id }}')"><i
                                            class='bx bxs-edit-alt'></i></x-secondary-button>

                                    @if ($isEntregaOuRetirada)
                                        <button type="button"
                                            title="COPIAR LINK DE ACOMPANHAMENTO"
                                            onclick="copiarLinkAcompanhamento('{{ route('pedido.acompanhar', $pedido->id) }}', this)"
                                            class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded-md font-semibold text-xs text-teal-700 uppercase tracking-widest shadow-sm hover:bg-teal-50 hover:border-teal-400 focus:outline-none transition ease-in-out duration-150">
                                            <i class='bx bx-link'></i>
                                        </button>
                                    @endif

                                    <form action="{{ route('pedido.restaurar', ['id' => $pedido->id]) }}"
                                        method="post">
                                        @csrf
                                        <x-primary-button title="RESTAURAR"><i
                                                class='bx bx-check-circle'></i></x-primary-button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="3" class="text-center py-4">Nenhuma pedido encontrado.</td>
                    </tr>
                @endif
            </tbody>
        </table>

        {{-- Modal de movimentação --}}
        @foreach ($pedidos as $pedido)
            <x-modal name="mov-pedido-{{ $pedido->id }}" :show="null" :maxWidth="'2xl'">
                <div class="p-6">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Movimentação de pedido') }}
                    </h2>

                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Lista todas as movimentações do pedido selecionado!') }}
                    </p>
                    <table class="border-b-2 border-gray-100 w-full">
                        <thead class="border-b-4">
                            <th>PEDIDO</th>
                            <th>MESA ANTERIOR</th>
                            <th>MESA ATUAL</th>
                            <th>USUÁRIO</th>
                        </thead>
                        <tbody class="text-center">
                            @forelse ($pedido->mov_pedido as $mov)
                                <tr>
                                    <td>{{ $mov->mov_pedido_pedido_id }}</td>
                                    <td>{{ $mov->sessaoMesaAntiga->mesa->mesa_nome }}</td>
                                    <td>{{ $mov->sessaoMesaAtual->mesa->mesa_nome }}</td>
                                    <td>{{ $mov->usuario->name_first }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">Nenhuma movimentação registrada</td>
                                </tr>
                            @endforelse

                        </tbody>
                    </table>
                </div>
            </x-modal>
        @endforeach

        {{-- Modal de alteração --}}
        @foreach ($pedidos as $pedido)
            <x-modal name="alterar-pedido-{{ $pedido->id }}" :show="null" :maxWidth="'2xl'">
                <div class="p-6">

                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Alterar de pedido: ' . $pedido->id) }}
                    </h2>

                    {{-- <p class="mt-1 text-sm text-gray-600">
                        {{ __('Lista todas as movimentações do pedido selecionado!') }}
                    </p> --}}

                    <div
                        class="flex flex-nowrap flex-col xl:flex-row items-start justify-between space-y-2 md:space-y-0">
                        <form action="{{ route('pedido.update', ['pedido' => $pedido]) }}" method="post"
                            class="w-full mt-6">
                            @csrf
                            @method('patch')
                            <p class="flex items-center gap-x-2 text-sm font-bold text-teal-700">
                                <i class='bx bxs-map-pin'></i>
                                <span>{{ __('Entrega') }}</span>
                            </p>
                            <x-radio-input :opcoes="$opcoes_entregas" :id="$pedido->id" :selectedId="$pedido->pedido_opcaoentrega_id"
                                name="pedido_opcaoentrega_id" />

                            {{-- ENDERECO ENTREGA --}}
                            <div id="endereco_entrega_{{ $pedido->id }}" class="w-full">
                                <x-input-label for="pedido_endereco_entrega" :value="__('Endereço para Entrega')" />
                                <x-text-input id="pedido_endereco_entrega" name="pedido_endereco_entrega" type="text"
                                    value="{{ $pedido->pedido_endereco_entrega }}" class="mt-1 w-full"
                                    autocomplete="off" />
                            </div>

                            <div class="mt-6 flex items-end">
                                <x-primary-button>{{ __('Alterar pedido') }}</x-primary-button>
                            </div>
                        </form>
                    </div>
                </div>
            </x-modal>
        @endforeach
        {{-- Modal de cancelamento (captura o motivo p/ análise de perdas).
             z-[70] para ficar acima de qualquer overlay (selector usa até z-[60]). --}}
        @foreach ($pedidos as $pedido)
            <div x-data="{ open: false }"
                x-on:open-modal.window="$event.detail === 'cancelar-pedido-{{ $pedido->id }}' && (open = true)"
                x-on:close-modal.window="$event.detail === 'cancelar-pedido-{{ $pedido->id }}' && (open = false)"
                x-on:keydown.escape.window="open = false"
                x-show="open" style="display: none;"
                class="fixed inset-0 z-[70] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-gray-500/75" x-on:click="open = false"></div>
                <div class="relative w-full max-w-md bg-white rounded-lg shadow-xl"
                    x-show="open" x-transition>
                    <form action="{{ route('pedido.cancelar', ['id' => $pedido->id]) }}" method="post" class="p-6">
                        @csrf
                        <h2 class="text-lg font-medium text-gray-900">
                            {{ __('Cancelar pedido #' . $pedido->id) }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('Selecione o motivo. Essa informação alimenta a análise de perdas.') }}
                        </p>

                        <div class="mt-4">
                            <x-input-label for="pedido_motivo_cancelamento_{{ $pedido->id }}" :value="__('Motivo')" />
                            <select id="pedido_motivo_cancelamento_{{ $pedido->id }}" name="pedido_motivo_cancelamento" required
                                class="mt-1 block w-full border-gray-300 focus:border-red-500 focus:ring-red-500 rounded-md shadow-sm">
                                <option value="" disabled selected>{{ __('Selecione...') }}</option>
                                @foreach (\App\Enums\MotivoCancelamentoEnum::paraSelect() as $valor => $rotulo)
                                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mt-6 flex justify-end gap-x-3">
                            <x-secondary-button type="button" x-on:click="open = false">
                                {{ __('Voltar') }}
                            </x-secondary-button>
                            <x-danger-button>{{ __('Confirmar cancelamento') }}</x-danger-button>
                        </div>
                    </form>
                </div>
            </div>
        @endforeach

        <div class="py-4">
            {{ $pedidos->links() }}
        </div>
    </div>
</section>

<script>
function copiarLinkAcompanhamento(url, btn) {
    navigator.clipboard.writeText(url).then(function () {
        const icon = btn.querySelector('i');
        icon.className = 'bx bx-check';
        btn.classList.add('border-teal-500', 'bg-teal-50');
        setTimeout(function () {
            icon.className = 'bx bx-link';
            btn.classList.remove('border-teal-500', 'bg-teal-50');
        }, 2000);
    });
}
</script>
