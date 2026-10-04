@php
    /** @var array{rodadas: array<int, array{pedido_id: int, mesa: string, garcom: string, consumo: float, taxa: float}>, consumo: float, taxa: float} $venda */
    $venda = $getRecord();
@endphp

<div class="w-full min-w-72 px-3 py-2 text-sm">
    <table class="w-full">
        <tbody>
            @foreach ($venda['rodadas'] as $rodada)
                <tr class="text-gray-700 dark:text-gray-300">
                    <td class="py-0.5 pr-3 whitespace-nowrap">Pedido #{{ $rodada['pedido_id'] }}</td>
                    <td class="py-0.5 pr-3 text-gray-500 dark:text-gray-400">{{ $rodada['mesa'] }}</td>
                    <td class="py-0.5 pr-3">{{ $rodada['garcom'] }}</td>
                    <td class="py-0.5 text-right whitespace-nowrap">R$ {{ number_format($rodada['taxa'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="border-t border-gray-200 font-semibold text-gray-900 dark:border-white/10 dark:text-white">
                <td class="pt-1 pr-3" colspan="3">Total da venda (consumo R$ {{ number_format($venda['consumo'], 2, ',', '.') }})</td>
                <td class="pt-1 text-right whitespace-nowrap">R$ {{ number_format($venda['taxa'], 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</div>
