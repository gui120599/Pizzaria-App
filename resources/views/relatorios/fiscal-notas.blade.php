<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Relatório fiscal (NFC-e)</title>

    @vite(['resources/css/app.css'])

    <style>
        @media print {
            @page { size: A4; margin: 1.5cm; }
            .no-print { display: none !important; }
            .secao, tr { break-inside: avoid; }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-800 text-xs">
    @php($brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.'))

    <button onclick="window.print()"
        class="no-print fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:bg-gray-700">
        Imprimir
    </button>

    <div class="mx-auto max-w-4xl bg-white p-8 shadow print:shadow-none print:p-0">
        <div class="mb-6 border-b-2 border-gray-800 pb-3">
            <h1 class="text-base font-bold">Relatório fiscal — NFC-e autorizadas</h1>
            <p class="text-gray-600">Período: {{ $inicio->format('d/m/Y') }} a {{ $fim->format('d/m/Y') }}</p>
            <p class="text-gray-600">Gerado em {{ $geradoEm->format('d/m/Y H:i') }} por {{ $geradoPor }}</p>
        </div>

        <div class="secao mb-6 grid grid-cols-4 gap-3">
            @foreach ([
                ['Notas', $resumo['notas']],
                ['Itens', $resumo['itens']],
                ['Bruto dos itens', $brl($resumo['bruto'])],
                ['Descontos', $brl($resumo['desconto'])],
                ['Líquido dos itens', $brl($resumo['liquido'])],
                ['Frete, taxa e ajustes', $brl($resumo['outros'])],
                ['Total das notas', $brl($resumo['total'])],
            ] as [$rotulo, $valor])
                <div class="rounded border border-gray-300 p-2">
                    <p class="text-gray-500">{{ $rotulo }}</p>
                    <p class="text-sm font-semibold">{{ $valor }}</p>
                </div>
            @endforeach
        </div>

        @foreach ($grupos as $dimensao => $grupo)
            <div class="secao mb-6">
                <h2 class="mb-1 text-sm font-semibold">Por {{ mb_strtolower($dimensoes[$dimensao]) }}</h2>
                @include('relatorios.partials.fiscal-notas-grupo', ['dimensao' => $dimensao, 'titulo' => $dimensoes[$dimensao], 'grupo' => $grupo])
            </div>
        @endforeach

        <div class="secao">
            <h2 class="mb-1 text-sm font-semibold">Notas do período</h2>
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-left uppercase text-gray-500">
                    <tr><th class="px-2 py-1">Autorização</th><th class="px-2 py-1">Nº / série</th><th class="px-2 py-1">Venda</th><th class="px-2 py-1">Cliente</th><th class="px-2 py-1 text-right">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($notas as $venda)
                        <tr>
                            <td class="px-2 py-1">{{ \Illuminate\Support\Carbon::parse($venda->nota_em)->format('d/m/Y H:i') }}</td>
                            <td class="px-2 py-1">{{ $venda->nota_numero ? $venda->nota_numero.' / '.$venda->nota_serie : '—' }}</td>
                            <td class="px-2 py-1">#{{ $venda->id }}</td>
                            <td class="px-2 py-1">{{ $venda->cliente?->cliente_nome ?? 'Consumidor' }}</td>
                            <td class="px-2 py-1 text-right">{{ $brl((float) $venda->venda_valor_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 font-semibold">
                    <tr><td colspan="4" class="px-2 py-1">TOTAL</td><td class="px-2 py-1 text-right">{{ $brl($resumo['total']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</body>

</html>
