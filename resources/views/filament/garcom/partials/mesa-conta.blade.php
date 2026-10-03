{{-- Conta da mesa: aba Conta no celular e painel lateral no desktop.
     Espera $sessao e $conta no escopo. --}}
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

        <div x-data="{ aberto: false }" class="relative">
            <button type="button" x-on:click="aberto = ! aberto"
                    class="w-full rounded-xl bg-gray-100 py-3 text-center text-sm font-semibold text-gray-800 active:scale-[.98] dark:bg-white/10 dark:text-white">
                Imprimir pré-conta ▾
            </button>
            <div x-show="aberto" x-cloak x-on:click.outside="aberto = false"
                 class="absolute left-0 z-20 mt-1 w-56 overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-white/10">
                @foreach (['agrupado' => 'Itens agrupados', 'rodada' => 'Por rodada', 'cliente' => 'Por pessoa'] as $modo => $rotulo)
                    <a href="{{ route('sessaoMesa.imprimir', ['id' => $sessao->id, 'modo' => $modo]) }}" target="_blank"
                       class="block px-4 py-3 text-sm text-gray-800 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-white/5">{{ $rotulo }}</a>
                @endforeach
            </div>
        </div>

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

        @if ($this->podeFecharMesa())
            <button type="button" wire:click="mountAction('fecharMesa')"
                    class="col-span-2 rounded-xl py-3 text-sm font-semibold text-red-600 ring-1 ring-red-300 active:scale-[.98] dark:ring-red-500/40">
                Fechar mesa (liberar para outro cliente)
            </button>
        @endif
    </div>

    {{-- Conta por pessoa + comanda individual --}}
    @php $porPessoa = $this->contaPorPessoa(); @endphp
    <div class="space-y-3 rounded-2xl bg-white p-4 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
        @include('filament.garcom.partials.pessoas-mesa')

        @if (count($porPessoa) > 0)
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($porPessoa as $clienteId => $parcial)
                    <li wire:key="parcial-{{ $clienteId }}" class="flex items-center gap-3 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $parcial['nome'] }}</p>
                            <p class="text-xs text-gray-500">
                                R$ {{ number_format($parcial['subtotal'], 2, ',', '.') }}
                                @if ($parcial['taxa'] > 0)
                                    + taxa R$ {{ number_format($parcial['taxa'], 2, ',', '.') }}
                                @endif
                            </p>
                        </div>
                        <span class="text-sm font-bold text-gray-900 dark:text-white">R$ {{ number_format($parcial['total'], 2, ',', '.') }}</span>
                        <a href="{{ route('sessaoMesa.imprimir', ['id' => $sessao->id, 'modo' => 'cliente', 'cliente' => $clienteId]) }}" target="_blank"
                           class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-700 ring-1 ring-gray-300 dark:text-gray-200 dark:ring-white/10">
                            Comanda
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div>
        @livewire('mesa-stone-cobranca', ['sessaoMesaId' => $sessao->id], key('stone-'.$sessao->id))
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">
        Dinheiro, PIX ou pagamento dividido: feche no caixa. A taxa de serviço desta conta é levada junto.
    </p>
</div>
