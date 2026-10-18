<x-filament-panels::page>
    @php
        $totais = $this->relatorio->totais();
        $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ([
            ['Itens de cortesia', $totais['itens']],
            ['Valor a preço de tabela', $brl($totais['valor_tabela'])],
            ['Custo estimado (estoque)', $brl($totais['custo'])],
        ] as [$rotulo, $valor])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $rotulo }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm text-gray-700 dark:text-gray-300">
            De
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="dataInicial" />
            </x-filament::input.wrapper>
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">
            Até
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="dataFinal" />
            </x-filament::input.wrapper>
        </label>
        <div class="min-w-64 flex-1">
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.300ms="busca" placeholder="Produto, motivo, autorizador ou nº do pedido/venda..." />
            </x-filament::input.wrapper>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-2">Data</th>
                    <th class="px-4 py-2">Origem</th>
                    <th class="px-4 py-2">Produto</th>
                    <th class="px-4 py-2 text-right">Qtd.</th>
                    <th class="px-4 py-2 text-right">Tabela</th>
                    <th class="px-4 py-2 text-right">Custo</th>
                    <th class="px-4 py-2">Motivo</th>
                    <th class="px-4 py-2">Autorizou</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($this->relatorio->linhas() as $linha)
                    <tr wire:key="cortesia-{{ $loop->index }}">
                        <td class="whitespace-nowrap px-4 py-2 text-gray-500 dark:text-gray-400">{{ $linha['data']->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-2">{{ $linha['origem'] }}</td>
                        <td class="px-4 py-2">{{ $linha['produto'] }}</td>
                        <td class="px-4 py-2 text-right">{{ rtrim(rtrim(number_format($linha['quantidade'], 3, ',', '.'), '0'), ',') }}</td>
                        <td class="px-4 py-2 text-right">{{ $brl($linha['valor_tabela']) }}</td>
                        <td class="px-4 py-2 text-right">{{ $brl($linha['custo']) }}</td>
                        <td class="px-4 py-2">{{ $linha['motivo'] ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $linha['autorizador'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-10 text-center text-gray-400 dark:text-gray-500">Nenhuma cortesia no período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        Cortesia sai do estoque (movimentação "Cortesia"), não é cobrada e não entra na NFC-e. O custo usa o custo médio atual do produto.
    </p>
</x-filament-panels::page>
