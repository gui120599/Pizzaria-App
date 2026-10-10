<x-filament-panels::page>
    @php
        $totais = $this->relatorio->totais();
        $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
    @endphp

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            ['Total em aberto', $brl($totais['total'])],
            ['Vencido', $brl($totais['vencido'])],
            ['Clientes devendo', $totais['clientes']],
            ['Títulos em aberto', $totais['titulos']],
        ] as [$rotulo, $valor])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $rotulo }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <div class="min-w-64 flex-1">
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.300ms="busca" placeholder="Cliente, nº da venda ou do pedido..." />
            </x-filament::input.wrapper>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <x-filament::input.checkbox wire:model.live="somenteVencidos" />
            Somente vencidos
        </label>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <x-filament::input.checkbox wire:model.live="agruparPorMes" />
            Agrupar por mês
        </label>
    </div>

    <div class="divide-y divide-gray-100 overflow-hidden rounded-xl shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:ring-white/10">
        @forelse ($this->relatorio->porCliente() as $debito)
            <x-debitos-cliente wire:key="debito-cliente-{{ $debito['cliente']?->id ?? 0 }}-{{ $agruparPorMes ? 'mes' : 'lista' }}" :debito="$debito" :por-mes="$agruparPorMes" :aberto="$agruparPorMes" />
        @empty
            <div class="bg-white p-10 text-center text-sm text-gray-400 dark:bg-gray-900 dark:text-gray-500">Nenhum cliente com débito em aberto.</div>
        @endforelse
    </div>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        "Em aberto" por pedido: saldo do título repartido na proporção do valor de cada pedido na venda. Clique no cliente para ver os títulos e no pedido para ver os itens.
    </p>
</x-filament-panels::page>
