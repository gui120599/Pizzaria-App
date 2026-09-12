<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Relatório de Contas a Pagar e a Receber</title>

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
                    <h1 class="text-base font-bold">Relatório de Contas a Pagar e a Receber</h1>
                    <p class="text-gray-600">
                        Período:
                        @if ($periodo[0] && $periodo[1])
                            {{ $periodo[0]->format('d/m/Y') }} a {{ $periodo[1]->format('d/m/Y') }}
                        @else
                            Todos os lançamentos
                        @endif
                    </p>
                </div>
            </div>
            <div class="text-right text-gray-600">
                <p>Emitido em {{ $geradoEm->format('d/m/Y H:i') }}</p>
                <p>por {{ $geradoPor }}</p>
            </div>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Indicadores</h2>
            <div class="grid grid-cols-3 gap-3">
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">A Pagar — Em aberto</p>
                    <p class="text-base font-bold">R$ {{ number_format($pagar['total_aberto'], 2, ',', '.') }}</p>
                    <p class="text-[10px] text-gray-500">Vencido: R$ {{ number_format($pagar['total_vencido'], 2, ',', '.') }} ({{ number_format($pagar['percentual_vencido'], 1, ',', '.') }}%)</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">A Receber — Em aberto</p>
                    <p class="text-base font-bold">R$ {{ number_format($receber['total_aberto'], 2, ',', '.') }}</p>
                    <p class="text-[10px] text-gray-500">Vencido: R$ {{ number_format($receber['total_vencido'], 2, ',', '.') }} ({{ number_format($receber['percentual_vencido'], 1, ',', '.') }}%)</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Índice de inadimplência</p>
                    <p class="text-base font-bold">{{ number_format($receber['indice_inadimplencia'] ?? 0, 1, ',', '.') }}%</p>
                    <p class="text-[10px] text-gray-500">Vencido sobre total a receber em aberto</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Prazo Médio de Pagamento</p>
                    <p class="text-base font-bold">{{ number_format($pmp, 0, ',', '.') }} dias</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Prazo Médio de Recebimento</p>
                    <p class="text-base font-bold">{{ number_format($pmr, 0, ',', '.') }} dias</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Ciclo financeiro (PMR − PMP)</p>
                    <p class="text-base font-bold">{{ number_format($cicloFinanceiro, 0, ',', '.') }} dias</p>
                </div>
            </div>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Saldo projetado de caixa (Receber − Pagar) por faixa de vencimento</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1.5 text-left">Vencido</th>
                        <th class="border p-1.5 text-right">Até 7 dias</th>
                        <th class="border p-1.5 text-right">8 a 15 dias</th>
                        <th class="border p-1.5 text-right">16 a 30 dias</th>
                        <th class="border p-1.5 text-right">31 a 60 dias</th>
                        <th class="border p-1.5 text-right">Mais de 60 dias</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        @foreach (['vencido', 'ate_7', 'de_8_15', 'de_16_30', 'de_31_60', 'mais_60'] as $faixa)
                            <td class="border p-1.5 text-right">R$ {{ number_format($saldoProjetadoPorFaixa[$faixa], 2, ',', '.') }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Aging — composição por prazo</h2>
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <p class="font-bold mb-1">A Pagar</p>
                    <table class="w-full border-collapse">
                        <tbody>
                            <tr><td class="border p-1">Vencido 1–15 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosPagar['1-15'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">Vencido 16–30 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosPagar['16-30'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">Vencido 31–60 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosPagar['31-60'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">Vencido +60 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosPagar['61+'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 7 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerPagar['7'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 15 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerPagar['15'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 30 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerPagar['30'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 60 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerPagar['60'], 2, ',', '.') }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <p class="font-bold mb-1">A Receber</p>
                    <table class="w-full border-collapse">
                        <tbody>
                            <tr><td class="border p-1">Vencido 1–15 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosReceber['1-15'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">Vencido 16–30 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosReceber['16-30'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">Vencido 31–60 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosReceber['31-60'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">Vencido +60 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingVencidosReceber['61+'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 7 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerReceber['7'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 15 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerReceber['15'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 30 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerReceber['30'], 2, ',', '.') }}</td></tr>
                            <tr><td class="border p-1">A vencer em 60 dias</td><td class="border p-1 text-right">R$ {{ number_format($agingAVencerReceber['60'], 2, ',', '.') }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Maiores saldos em aberto</h2>
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <p class="font-bold mb-1">Top fornecedores (A Pagar)</p>
                    <table class="w-full border-collapse">
                        <tbody>
                            @forelse ($topFornecedores as $linha)
                                <tr><td class="border p-1">{{ $linha['nome'] }}</td><td class="border p-1 text-right">R$ {{ number_format($linha['saldo'], 2, ',', '.') }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="border p-1 italic">Nenhum saldo em aberto.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div>
                    <p class="font-bold mb-1">Top clientes (A Receber)</p>
                    <table class="w-full border-collapse">
                        <tbody>
                            @forelse ($topClientes as $linha)
                                <tr><td class="border p-1">{{ $linha['nome'] }}</td><td class="border p-1 text-right">R$ {{ number_format($linha['saldo'], 2, ',', '.') }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="border p-1 italic">Nenhum saldo em aberto.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="secao">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Detalhamento dos lançamentos</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1.5 text-left">Tipo</th>
                        <th class="border p-1.5 text-left">Descrição</th>
                        <th class="border p-1.5 text-left">Favorecido</th>
                        <th class="border p-1.5 text-left">Vencimento</th>
                        <th class="border p-1.5 text-right">Valor</th>
                        <th class="border p-1.5 text-right">Pago</th>
                        <th class="border p-1.5 text-right">Restante</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse (['Vencido', 'A vencer', 'Pago', 'Cancelado'] as $grupo)
                        @continue(! isset($lancamentosDetalhados[$grupo]))
                        <tr><td colspan="7" class="bg-indigo-50 font-bold p-1.5">{{ $grupo }} ({{ $lancamentosDetalhados[$grupo]->count() }})</td></tr>
                        @foreach ($lancamentosDetalhados[$grupo] as $lancamento)
                            <tr>
                                <td class="border p-1">{{ $lancamento->tipo->getLabel() }}</td>
                                <td class="border p-1">{{ $lancamento->descricao }}</td>
                                <td class="border p-1">{{ $lancamento->nomeFavorecido ?? '—' }}</td>
                                <td class="border p-1">{{ $lancamento->vencimento?->format('d/m/Y') }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($lancamento->valor, 2, ',', '.') }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($lancamento->valorPago, 2, ',', '.') }}</td>
                                <td class="border p-1 text-right">R$ {{ number_format($lancamento->valorRestante, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                        <tr class="bg-gray-50 font-bold">
                            <td class="border p-1" colspan="4">Subtotal — {{ $grupo }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($lancamentosDetalhados[$grupo]->sum('valor'), 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($lancamentosDetalhados[$grupo]->sum('valorPago'), 2, ',', '.') }}</td>
                            <td class="border p-1 text-right">R$ {{ number_format($lancamentosDetalhados[$grupo]->sum('valorRestante'), 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="border p-2 text-center italic">Nenhum lançamento encontrado para os filtros selecionados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        window.print();
    </script>
</body>

</html>
