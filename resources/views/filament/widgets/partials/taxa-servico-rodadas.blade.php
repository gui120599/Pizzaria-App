@php
    /** @var array{rodadas: array<int, array{pedido_id: int, mesa: string, garcom: string, consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float}>, consumo: float, taxa: float, desconto_maquininha: float, desconto_imposto: float, taxa_liquida: float} $venda */
    $venda = $getRecord();
    $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
@endphp

<div class="w-full min-w-96 px-3 py-2 text-sm">
    <table class="w-full">
        <thead>
            <tr class="text-xs text-gray-500 dark:text-gray-400">
                <th class="pb-1 pr-3 text-left font-medium">Pedido</th>
                <th class="pb-1 pr-3 text-left font-medium">Mesa</th>
                <th class="pb-1 pr-3 text-left font-medium">Garçom</th>
                <th class="pb-1 pr-3 text-right font-medium">Taxa</th>
                <th class="pb-1 pr-3 text-right font-medium">Maquininha</th>
                <th class="pb-1 pr-3 text-right font-medium">Imposto</th>
                <th class="pb-1 text-right font-medium">Líquido</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($venda['rodadas'] as $rodada)
                <tr class="text-gray-700 dark:text-gray-300">
                    <td class="py-0.5 pr-3 whitespace-nowrap">#{{ $rodada['pedido_id'] }}</td>
                    <td class="py-0.5 pr-3 text-gray-500 dark:text-gray-400">{{ $rodada['mesa'] }}</td>
                    <td class="py-0.5 pr-3">{{ $rodada['garcom'] }}</td>
                    <td class="py-0.5 pr-3 text-right whitespace-nowrap">{{ $brl($rodada['taxa']) }}</td>
                    <td class="py-0.5 pr-3 text-right whitespace-nowrap text-danger-600 dark:text-danger-400">{{ $brl($rodada['desconto_maquininha']) }}</td>
                    <td class="py-0.5 pr-3 text-right whitespace-nowrap text-danger-600 dark:text-danger-400">{{ $brl($rodada['desconto_imposto']) }}</td>
                    <td class="py-0.5 text-right whitespace-nowrap font-medium">{{ $brl($rodada['taxa_liquida']) }}</td>
                </tr>
            @endforeach
            <tr class="border-t border-gray-200 font-semibold text-gray-900 dark:border-white/10 dark:text-white">
                <td class="pt-1 pr-3" colspan="3">Total da venda (consumo {{ $brl($venda['consumo']) }})</td>
                <td class="pt-1 pr-3 text-right whitespace-nowrap">{{ $brl($venda['taxa']) }}</td>
                <td class="pt-1 pr-3 text-right whitespace-nowrap">{{ $brl($venda['desconto_maquininha']) }}</td>
                <td class="pt-1 pr-3 text-right whitespace-nowrap">{{ $brl($venda['desconto_imposto']) }}</td>
                <td class="pt-1 text-right whitespace-nowrap">{{ $brl($venda['taxa_liquida']) }}</td>
            </tr>
        </tbody>
    </table>
</div>
