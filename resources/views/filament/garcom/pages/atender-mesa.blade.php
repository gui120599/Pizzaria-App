{{--
    Atendimento da mesa no Painel do Garçom. O PedidoProdutoSelector grava os
    itens direto no rascunho da rodada; o botão dele dispara #pedido-form, que
    aqui chama enviarRodada(). O poll só vigia rodadas prontas e o fechamento
    da conta — o selector é um componente filho e não re-renderiza com ele.
--}}
<x-filament-panels::page>
    @if ($sessaoId)
        @php
            $sessao = $this->sessao();
            $conta = $this->conta();
        @endphp

        <div wire:poll.{{ (int) config('pizzaria.salao.polling_mesa_segundos') }}s="atualizar" class="space-y-4">
            @include('filament.garcom.partials.sem-conexao')
            @include('filament.garcom.partials.alerta-pronto')

            {{-- Cabeçalho da conta --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                <a href="{{ \App\Filament\Garcom\Pages\MapaMesas::getUrl() }}" wire:navigate
                   class="font-semibold text-primary-600 dark:text-primary-400">&larr; Mesas</a>
                <span>{{ $sessao->sessao_mesa_pessoas }} pessoa(s)</span>
                <span>Aberta há {{ (int) $sessao->created_at->diffInMinutes(now()) }} min</span>
                <span>Garçom: {{ $sessao->garcom?->name_first ?: $sessao->garcom?->name }}</span>
                @if ($sessao->contaSolicitada())
                    <span class="rounded-full bg-violet-600 px-2 py-0.5 text-xs font-semibold text-white">Pediu a conta</span>
                @endif
            </div>

            {{-- Abas --}}
            <div class="grid grid-cols-3 gap-1 rounded-2xl bg-gray-100 p-1 dark:bg-white/5">
                @foreach (['pedir' => 'Pedir', 'rodadas' => 'Rodadas', 'conta' => 'Conta'] as $valor => $rotulo)
                    <button type="button" wire:click="$set('aba', '{{ $valor }}')"
                            @class([
                                'rounded-xl py-3 text-base font-semibold transition',
                                'bg-white text-gray-900 shadow dark:bg-gray-800 dark:text-white' => $aba === $valor,
                                'text-gray-500 dark:text-gray-400' => $aba !== $valor,
                            ])>
                        {{ $rotulo }}
                    </button>
                @endforeach
            </div>

            {{-- Pedir --}}
            <div @class(['hidden' => $aba !== 'pedir'])>
                @livewire('pedido-produto-selector', [
                    'pedidoId' => $rascunhoId,
                    'saveButtonLabel' => 'Enviar para a cozinha',
                    'sessaoMesaClientes' => $this->clientesDaMesa(),
                ], key('rodada-'.$rascunhoId))

                <form id="pedido-form" wire:submit="enviarRodada" class="hidden"></form>
            </div>

            {{-- Rodadas --}}
            @if ($aba === 'rodadas')
                <div class="space-y-3">
                    @forelse ($this->rodadas() as $rodada)
                        @php $statusRodada = \App\Enums\StatusPedidoEnum::tryFrom($rodada->pedido_status); @endphp
                        <div wire:key="rodada-{{ $rodada->id }}"
                             class="overflow-hidden rounded-2xl bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-white/10">
                                <div>
                                    <span class="font-semibold text-gray-900 dark:text-white">Rodada #{{ $rodada->id }}</span>
                                    <span class="ml-2 text-xs text-gray-500">{{ $rodada->pedido_datahora_abertura ? \Illuminate\Support\Carbon::parse($rodada->pedido_datahora_abertura)->format('H:i') : '' }} · {{ $rodada->garcom?->name_first ?: $rodada->garcom?->name }}</span>
                                </div>
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-sky-100 text-sky-800' => $statusRodada === \App\Enums\StatusPedidoEnum::ABERTO,
                                    'bg-amber-100 text-amber-800' => $statusRodada === \App\Enums\StatusPedidoEnum::PREPARANDO,
                                    'bg-emerald-500 text-white' => $statusRodada === \App\Enums\StatusPedidoEnum::PRONTO,
                                    'bg-gray-100 text-gray-700' => in_array($statusRodada, [\App\Enums\StatusPedidoEnum::ENTREGUE, \App\Enums\StatusPedidoEnum::FINALIZADO], true),
                                    'bg-red-100 text-red-700' => $statusRodada === \App\Enums\StatusPedidoEnum::CANCELADO,
                                ])>
                                    {{ match ($statusRodada) {
                                        \App\Enums\StatusPedidoEnum::ABERTO => 'Enviado',
                                        \App\Enums\StatusPedidoEnum::PREPARANDO => 'Em preparo',
                                        \App\Enums\StatusPedidoEnum::PRONTO => 'Pronto',
                                        \App\Enums\StatusPedidoEnum::ENTREGUE => 'Entregue',
                                        \App\Enums\StatusPedidoEnum::FINALIZADO => 'Pago',
                                        \App\Enums\StatusPedidoEnum::CANCELADO => 'Cancelado',
                                        default => $rodada->pedido_status,
                                    } }}
                                </span>
                            </div>

                            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                                @foreach ($rodada->item_pedido_pedido_id as $item)
                                    <li wire:key="item-{{ $item->id }}" class="flex items-start gap-3 px-4 py-2.5">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                {{ \App\Support\FormatoQuantidade::item($item->item_pedido_quantidade) }}× {{ $item->nomeProduto() }}
                                            </p>
                                            @if ($item->adicionaisItemPedido->isNotEmpty())
                                                <p class="text-xs text-gray-500">+ {{ $item->adicionaisItemPedido->map(fn ($a) => $a->adicional?->adicional_nome)->filter()->implode(', ') }}</p>
                                            @endif
                                            @if ($item->item_pedido_observacao)
                                                <p class="text-xs italic text-amber-700 dark:text-amber-400">{{ $item->item_pedido_observacao }}</p>
                                            @endif
                                        </div>
                                        <span class="text-sm text-gray-700 dark:text-gray-300">R$ {{ number_format((float) $item->item_pedido_valor, 2, ',', '.') }}</span>
                                        @if (! in_array($statusRodada, [\App\Enums\StatusPedidoEnum::CANCELADO, \App\Enums\StatusPedidoEnum::FINALIZADO], true) && $item->item_pedido_venda_id === null)
                                            <button type="button"
                                                    wire:click="mountAction('cancelarItem', { item: {{ $item->id }} })"
                                                    class="rounded-lg px-2 py-1 text-xs font-semibold text-red-600 ring-1 ring-red-200 active:scale-95 dark:ring-red-500/30">
                                                Cancelar
                                            </button>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            @if ($statusRodada === \App\Enums\StatusPedidoEnum::PRONTO)
                                <div class="p-3">
                                    <button type="button" wire:click="marcarEntregue({{ $rodada->id }})" wire:loading.attr="disabled"
                                            class="w-full rounded-xl bg-emerald-600 py-3 text-base font-semibold text-white active:scale-[.98]">
                                        Servido na mesa
                                    </button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-white/10">
                            Nenhuma rodada enviada ainda.
                        </div>
                    @endforelse
                </div>
            @endif

            {{-- Conta --}}
            @if ($aba === 'conta')
                <div class="space-y-4">
                    <div class="rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                        <dl class="space-y-2 text-base">
                            <div class="flex justify-between">
                                <dt class="text-gray-600 dark:text-gray-300">Consumo</dt>
                                <dd class="font-medium text-gray-900 dark:text-white">R$ {{ number_format($conta['subtotal'], 2, ',', '.') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-600 dark:text-gray-300">
                                    Taxa de serviço
                                    @if ($conta['percentual'] > 0)
                                        ({{ number_format($conta['percentual'], 0) }}%)
                                    @endif
                                </dt>
                                <dd class="font-medium text-gray-900 dark:text-white">R$ {{ number_format($conta['taxa'], 2, ',', '.') }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-gray-100 pt-2 text-xl font-bold dark:border-white/10">
                                <dt class="text-gray-900 dark:text-white">Total</dt>
                                <dd class="text-gray-900 dark:text-white">R$ {{ number_format($conta['total'], 2, ',', '.') }}</dd>
                            </div>
                            @if ($sessao->sessao_mesa_pessoas > 1 && $conta['total'] > 0)
                                <div class="flex justify-between text-sm text-gray-500">
                                    <dt>Por pessoa ({{ $sessao->sessao_mesa_pessoas }})</dt>
                                    <dd>R$ {{ number_format($conta['total'] / $sessao->sessao_mesa_pessoas, 2, ',', '.') }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" wire:click="alternarContaSolicitada"
                                class="col-span-2 rounded-xl bg-violet-600 py-4 text-base font-semibold text-white active:scale-[.98]">
                            {{ $sessao->contaSolicitada() ? 'Desfazer pedido de conta' : 'Mesa pediu a conta' }}
                        </button>

                        <a href="{{ route('sessaoMesa.imprimir', ['id' => $sessao->id]) }}" target="_blank"
                           class="rounded-xl bg-gray-100 py-3 text-center text-sm font-semibold text-gray-800 active:scale-[.98] dark:bg-white/10 dark:text-white">
                            Imprimir pré-conta
                        </a>

                        <button type="button" wire:click="mountAction('alterarPessoas')"
                                class="rounded-xl bg-gray-100 py-3 text-sm font-semibold text-gray-800 active:scale-[.98] dark:bg-white/10 dark:text-white">
                            Pessoas na mesa
                        </button>

                        @if ($sessao->temTaxaServico())
                            <button type="button" wire:click="mountAction('removerTaxa')"
                                    class="rounded-xl bg-gray-100 py-3 text-sm font-semibold text-red-600 active:scale-[.98] dark:bg-white/10">
                                Tirar taxa de serviço
                            </button>
                        @else
                            <button type="button" wire:click="restaurarTaxa"
                                    class="rounded-xl bg-gray-100 py-3 text-sm font-semibold text-gray-800 active:scale-[.98] dark:bg-white/10 dark:text-white">
                                Incluir taxa de serviço
                            </button>
                        @endif

                        <button type="button" wire:click="mountAction('transferirMesa')"
                                class="rounded-xl bg-gray-100 py-3 text-sm font-semibold text-gray-800 active:scale-[.98] dark:bg-white/10 dark:text-white">
                            Transferir mesa
                        </button>
                    </div>

                    <div>
                        @livewire('mesa-stone-cobranca', ['sessaoMesaId' => $sessao->id], key('stone-'.$sessao->id))
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Dinheiro, PIX ou pagamento dividido: feche no caixa. A taxa de serviço desta conta é levada junto.
                    </p>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
