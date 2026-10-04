<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Relatório de Taxa de Serviço</title>

    @vite(['resources/css/app.css'])

    <style>
        @media print {
            @page { size: A4; margin: 1.5cm; }
            .no-print { display: none !important; }
            .card, .secao, tr { break-inside: avoid; }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800 text-xs">
    <button onclick="window.print()"
        class="no-print fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:bg-gray-700">
        Imprimir
    </button>

    <div id="conteudo" class="mx-auto max-w-4xl bg-white p-8 shadow print:shadow-none print:p-0">
        <div class="flex items-center justify-between border-b-2 border-gray-800 pb-3 mb-6">
            <div class="flex items-center gap-4">
                <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="Logo" class="h-14">
                <div>
                    <h1 class="text-base font-bold">Relatório de Taxa de Serviço por Garçom</h1>
                    <p class="text-gray-600">
                        Período: {{ $periodo[0]->format('d/m/Y') }} a {{ $periodo[1]->format('d/m/Y') }}
                        @if ($garcomFiltrado)
                            — Garçom: {{ $garcomFiltrado }}
                        @endif
                    </p>
                    <p class="text-gray-600">Atribuição: {{ $atribuicao }}</p>
                </div>
            </div>
            <div class="text-right text-gray-600">
                <p>Emitido em {{ $geradoEm->format('d/m/Y H:i') }}</p>
                <p>por {{ $geradoPor }}</p>
            </div>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Indicadores</h2>
            <div class="grid grid-cols-4 gap-3">
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Taxa de serviço (bruta)</p>
                    <p class="text-base font-bold">R$ {{ number_format($totais['taxa'], 2, ',', '.') }}</p>
                    <p class="text-[10px] text-gray-500">Sobre R$ {{ number_format($totais['consumo'], 2, ',', '.') }} de consumo</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Taxa líquida</p>
                    <p class="text-base font-bold">R$ {{ number_format($totais['taxa_liquida'], 2, ',', '.') }}</p>
                    <p class="text-[10px] text-gray-500">Maquininha R$ {{ number_format($totais['desconto_maquininha'], 2, ',', '.') }} · Imposto R$ {{ number_format($totais['desconto_imposto'], 2, ',', '.') }}</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Mesas atendidas</p>
                    <p class="text-base font-bold">{{ $totais['mesas'] }}</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Mesas sem taxa</p>
                    <p class="text-base font-bold">{{ $semTaxa['mesas'] }}</p>
                    <p class="text-[10px] text-gray-500">R$ {{ number_format($semTaxa['valor'], 2, ',', '.') }} não cobrados</p>
                </div>
            </div>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Por garçom</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Garçom</th>
                        <th class="border p-1 text-right">Mesas</th>
                        <th class="border p-1 text-right">Vendas</th>
                        <th class="border p-1 text-right">Consumo</th>
                        <th class="border p-1 text-right">Taxa bruta</th>
                        <th class="border p-1 text-right">Maquininha</th>
                        <th class="border p-1 text-right">Imposto</th>
                        <th class="border p-1 text-right">Líquido</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($porGarcom as $garcom)
                        <tr>
                            <td class="border p-1">{{ $garcom['garcom'] }}</td>
                            <td class="border p-1 text-right">{{ $garcom['mesas'] }}</td>
                            <td class="border p-1 text-right">{{ $garcom['vendas'] }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($garcom['consumo'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($garcom['taxa'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($garcom['desconto_maquininha'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($garcom['desconto_imposto'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right font-semibold">R$ {{ number_format($garcom['taxa_liquida'], 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="border p-2 text-center italic">Nenhuma taxa de serviço no período.</td></tr>
                    @endforelse
                </tbody>
                @if ($porGarcom->isNotEmpty())
                    <tfoot>
                        <tr class="bg-gray-100 font-bold">
                            <td class="border p-1" colspan="3">Total</td>
                            <td class="border p-1 text-right">R$ {{ number_format($totais['consumo'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($totais['taxa'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($totais['desconto_maquininha'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($totais['desconto_imposto'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($totais['taxa_liquida'], 2, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="secao">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Detalhamento por venda</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Pedido</th>
                        <th class="border p-1 text-left">Mesa</th>
                        <th class="border p-1 text-left">Garçom</th>
                        <th class="border p-1 text-right">Consumo</th>
                        <th class="border p-1 text-right">Taxa</th>
                        <th class="border p-1 text-right">Maquininha</th>
                        <th class="border p-1 text-right">Imposto</th>
                        <th class="border p-1 text-right">Líquido</th>
                    </tr>
                </thead>
                @forelse ($porVenda as $venda)
                    <tbody>
                        <tr class="bg-gray-50">
                            <td class="border p-1 font-semibold" colspan="8">
                                Venda #{{ $venda['venda_id'] }} — {{ $venda['finalizada_em']->format('d/m/Y H:i') }}
                            </td>
                        </tr>
                        @foreach ($venda['rodadas'] as $rodada)
                            <tr>
                                <td class="border p-1">#{{ $rodada['pedido_id'] }}</td>
                                <td class="border p-1">{{ $rodada['mesa'] }}</td>
                                <td class="border p-1">{{ $rodada['garcom'] }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($rodada['consumo'], 2, ',', '.') }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($rodada['taxa'], 2, ',', '.') }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($rodada['desconto_maquininha'], 2, ',', '.') }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($rodada['desconto_imposto'], 2, ',', '.') }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($rodada['taxa_liquida'], 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-bold">
                            <td class="border p-1" colspan="3">Total da venda</td>
                            <td class="border p-1 text-right">R$ {{ number_format($venda['consumo'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($venda['taxa'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($venda['desconto_maquininha'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($venda['desconto_imposto'], 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($venda['taxa_liquida'], 2, ',', '.') }}</td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="8" class="border p-2 text-center italic">Nenhuma venda encontrada para os filtros selecionados.</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>
    </div>

    <script>
        window.print();
    </script>
</body>

</html>
