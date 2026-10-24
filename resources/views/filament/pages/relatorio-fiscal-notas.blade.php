<x-filament-panels::page>
    @php
        $resumo = $this->relatorio->resumo();
        $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
        $dimensoes = \App\Services\RelatorioFiscalNotasService::DIMENSOES;
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm text-gray-700 dark:text-gray-300">
            Autorizadas de
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="dataInicial" />
            </x-filament::input.wrapper>
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">
            até
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="dataFinal" />
            </x-filament::input.wrapper>
        </label>
        <div class="ml-auto flex gap-2">
            <x-filament::button tag="a" color="gray" icon="heroicon-o-table-cells" :href="route('relatorios.fiscal_notas.csv', $this->parametrosExportacao())">CSV / Excel</x-filament::button>
            <x-filament::button tag="a" color="gray" icon="heroicon-o-printer" target="_blank" :href="route('relatorios.fiscal_notas.imprimir', $this->parametrosExportacao())">Imprimir / PDF</x-filament::button>
        </div>
    </div>

    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Agrupamentos exibidos</p>
        <div class="flex flex-wrap gap-x-4 gap-y-4">
            @foreach ($dimensoes as $chave => $nome)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <x-filament::input.checkbox wire:model.live="agrupamentos" :value="$chave" />
                    {{ $nome }}
                </label>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            ['Notas autorizadas', $resumo['notas']],
            ['Itens', $resumo['itens']],
            ['Bruto dos itens', $brl($resumo['bruto'])],
            ['Descontos', $brl($resumo['desconto'])],
            ['Líquido dos itens', $brl($resumo['liquido'])],
            ['Frete, taxa de serviço e ajustes', $brl($resumo['outros'])],
            ['Total das notas', $brl($resumo['total'])],
        ] as [$rotulo, $valor])
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $rotulo }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    @foreach ($dimensoes as $chave => $nome)
        @if (in_array($chave, $this->agrupamentos, true))
            <div wire:key="grupo-{{ $chave }}">
                <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ \App\Services\RelatorioFiscalNotasService::TITULOS[$chave] }}</h3>
                <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    @include('relatorios.partials.fiscal-notas-grupo', ['dimensao' => $chave, 'titulo' => $nome, 'grupo' => $this->relatorio->agrupar($chave)])
                </div>
            </div>
        @endif
    @endforeach

    <details class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">Notas do período ({{ $resumo['notas'] }})</summary>
        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-100 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2">Autorização</th>
                        <th class="px-4 py-2">Nº / série</th>
                        <th class="px-4 py-2">Venda</th>
                        <th class="px-4 py-2">Cliente</th>
                        <th class="px-4 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($this->relatorio->notas() as $venda)
                        <tr wire:key="nota-{{ $venda->id }}">
                            <td class="whitespace-nowrap px-4 py-2">{{ \Illuminate\Support\Carbon::parse($venda->nota_em)->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2">{{ $venda->nota_numero ? $venda->nota_numero.' / '.$venda->nota_serie : '—' }}</td>
                            <td class="px-4 py-2">#{{ $venda->id }}</td>
                            <td class="px-4 py-2">{{ $venda->cliente?->cliente_nome ?? 'Consumidor' }}</td>
                            <td class="px-4 py-2 text-right">{{ $brl((float) $venda->venda_valor_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 font-semibold dark:bg-white/5">
                    <tr><td colspan="4" class="px-4 py-2">TOTAL</td><td class="px-4 py-2 text-right">{{ $brl($resumo['total']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </details>

    <p class="text-xs text-gray-400 dark:text-gray-500">
        Só NFC-e autorizadas (pela data de autorização). Os itens seguem o que vai na nota: cortesia e itens removidos ficam fora.
    </p>
</x-filament-panels::page>
