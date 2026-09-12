<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de Contas a Pagar e a Receber</title>
    <style>
        @page { size: A4; margin: 2cm 1.5cm; }

        * { box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
        }

        .cabecalho {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #1f2937;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }

        .cabecalho img { height: 50px; }

        .cabecalho h1 { font-size: 16px; margin: 0 0 4px; }

        .cabecalho .meta { font-size: 10px; color: #4b5563; text-align: right; }

        .secao { margin-bottom: 20px; page-break-inside: avoid; }

        .secao h2 {
            font-size: 13px;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 4px;
            margin: 0 0 10px;
        }

        .cards {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 8px 10px;
            width: 31%;
            page-break-inside: avoid;
        }

        .card .label { font-size: 9px; color: #6b7280; text-transform: uppercase; }
        .card .valor { font-size: 15px; font-weight: bold; margin-top: 2px; }
        .card .desc { font-size: 9px; color: #6b7280; margin-top: 2px; }

        table.dados { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.dados th, table.dados td { padding: 4px 6px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        table.dados th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; color: #4b5563; }
        table.dados td.num, table.dados th.num { text-align: right; }

        .grupo-titulo {
            background: #eef2ff;
            font-weight: bold;
            padding: 4px 6px;
        }

        .subtotal td { font-weight: bold; background: #f9fafb; border-top: 1px solid #9ca3af; }

        .grid-2 { display: flex; gap: 16px; }
        .grid-2 > div { flex: 1; }

        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <div class="cabecalho">
        <div style="display:flex; align-items:center; gap:12px;">
            <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="Logo">
            <div>
                <h1>Relatório de Contas a Pagar e a Receber</h1>
                <div style="font-size:10px; color:#4b5563;">
                    Período:
                    @if ($periodo[0] && $periodo[1])
                        {{ $periodo[0]->format('d/m/Y') }} a {{ $periodo[1]->format('d/m/Y') }}
                    @else
                        Todos os lançamentos
                    @endif
                </div>
            </div>
        </div>
        <div class="meta">
            Emitido em {{ $geradoEm->format('d/m/Y H:i') }}<br>
            por {{ $geradoPor }}
        </div>
    </div>

    <div class="secao">
        <h2>Indicadores</h2>
        <div class="cards">
            <div class="card">
                <div class="label">A Pagar — Em aberto</div>
                <div class="valor">R$ {{ number_format($pagar['total_aberto'], 2, ',', '.') }}</div>
                <div class="desc">Vencido: R$ {{ number_format($pagar['total_vencido'], 2, ',', '.') }} ({{ number_format($pagar['percentual_vencido'], 1, ',', '.') }}%)</div>
            </div>
            <div class="card">
                <div class="label">A Receber — Em aberto</div>
                <div class="valor">R$ {{ number_format($receber['total_aberto'], 2, ',', '.') }}</div>
                <div class="desc">Vencido: R$ {{ number_format($receber['total_vencido'], 2, ',', '.') }} ({{ number_format($receber['percentual_vencido'], 1, ',', '.') }}%)</div>
            </div>
            <div class="card">
                <div class="label">Índice de inadimplência</div>
                <div class="valor">{{ number_format($receber['indice_inadimplencia'] ?? 0, 1, ',', '.') }}%</div>
                <div class="desc">Vencido sobre total a receber em aberto</div>
            </div>
            <div class="card">
                <div class="label">Prazo Médio de Pagamento</div>
                <div class="valor">{{ number_format($pmp, 0, ',', '.') }} dias</div>
            </div>
            <div class="card">
                <div class="label">Prazo Médio de Recebimento</div>
                <div class="valor">{{ number_format($pmr, 0, ',', '.') }} dias</div>
            </div>
            <div class="card">
                <div class="label">Ciclo financeiro (PMR − PMP)</div>
                <div class="valor">{{ number_format($cicloFinanceiro, 0, ',', '.') }} dias</div>
            </div>
        </div>
    </div>

    <div class="secao">
        <h2>Saldo projetado de caixa (Receber − Pagar) por faixa de vencimento</h2>
        <table class="dados">
            <thead>
                <tr>
                    <th>Vencido</th>
                    <th class="num">Até 7 dias</th>
                    <th class="num">8 a 15 dias</th>
                    <th class="num">16 a 30 dias</th>
                    <th class="num">31 a 60 dias</th>
                    <th class="num">Mais de 60 dias</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    @foreach (['vencido', 'ate_7', 'de_8_15', 'de_16_30', 'de_31_60', 'mais_60'] as $faixa)
                        <td class="num">R$ {{ number_format($saldoProjetadoPorFaixa[$faixa], 2, ',', '.') }}</td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>

    <div class="secao">
        <h2>Aging — composição por prazo</h2>
        <div class="grid-2">
            <div>
                <strong>A Pagar</strong>
                <table class="dados">
                    <tbody>
                        <tr><td>Vencido 1–15 dias</td><td class="num">R$ {{ number_format($agingVencidosPagar['1-15'], 2, ',', '.') }}</td></tr>
                        <tr><td>Vencido 16–30 dias</td><td class="num">R$ {{ number_format($agingVencidosPagar['16-30'], 2, ',', '.') }}</td></tr>
                        <tr><td>Vencido 31–60 dias</td><td class="num">R$ {{ number_format($agingVencidosPagar['31-60'], 2, ',', '.') }}</td></tr>
                        <tr><td>Vencido +60 dias</td><td class="num">R$ {{ number_format($agingVencidosPagar['61+'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 7 dias</td><td class="num">R$ {{ number_format($agingAVencerPagar['7'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 15 dias</td><td class="num">R$ {{ number_format($agingAVencerPagar['15'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 30 dias</td><td class="num">R$ {{ number_format($agingAVencerPagar['30'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 60 dias</td><td class="num">R$ {{ number_format($agingAVencerPagar['60'], 2, ',', '.') }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div>
                <strong>A Receber</strong>
                <table class="dados">
                    <tbody>
                        <tr><td>Vencido 1–15 dias</td><td class="num">R$ {{ number_format($agingVencidosReceber['1-15'], 2, ',', '.') }}</td></tr>
                        <tr><td>Vencido 16–30 dias</td><td class="num">R$ {{ number_format($agingVencidosReceber['16-30'], 2, ',', '.') }}</td></tr>
                        <tr><td>Vencido 31–60 dias</td><td class="num">R$ {{ number_format($agingVencidosReceber['31-60'], 2, ',', '.') }}</td></tr>
                        <tr><td>Vencido +60 dias</td><td class="num">R$ {{ number_format($agingVencidosReceber['61+'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 7 dias</td><td class="num">R$ {{ number_format($agingAVencerReceber['7'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 15 dias</td><td class="num">R$ {{ number_format($agingAVencerReceber['15'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 30 dias</td><td class="num">R$ {{ number_format($agingAVencerReceber['30'], 2, ',', '.') }}</td></tr>
                        <tr><td>A vencer em 60 dias</td><td class="num">R$ {{ number_format($agingAVencerReceber['60'], 2, ',', '.') }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="secao">
        <h2>Maiores saldos em aberto</h2>
        <div class="grid-2">
            <div>
                <strong>Top fornecedores (A Pagar)</strong>
                <table class="dados">
                    <tbody>
                        @forelse ($topFornecedores as $linha)
                            <tr><td>{{ $linha['nome'] }}</td><td class="num">R$ {{ number_format($linha['saldo'], 2, ',', '.') }}</td></tr>
                        @empty
                            <tr><td colspan="2">Nenhum saldo em aberto.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                <strong>Top clientes (A Receber)</strong>
                <table class="dados">
                    <tbody>
                        @forelse ($topClientes as $linha)
                            <tr><td>{{ $linha['nome'] }}</td><td class="num">R$ {{ number_format($linha['saldo'], 2, ',', '.') }}</td></tr>
                        @empty
                            <tr><td colspan="2">Nenhum saldo em aberto.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="secao">
        <h2>Detalhamento dos lançamentos</h2>
        <table class="dados">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Descrição</th>
                    <th>Favorecido</th>
                    <th>Vencimento</th>
                    <th class="num">Valor</th>
                    <th class="num">Pago</th>
                    <th class="num">Restante</th>
                </tr>
            </thead>
            <tbody>
                @forelse (['Vencido', 'A vencer', 'Pago', 'Cancelado'] as $grupo)
                    @continue(! isset($lancamentosDetalhados[$grupo]))
                    <tr><td colspan="7" class="grupo-titulo">{{ $grupo }} ({{ $lancamentosDetalhados[$grupo]->count() }})</td></tr>
                    @foreach ($lancamentosDetalhados[$grupo] as $lancamento)
                        <tr>
                            <td>{{ $lancamento->tipo->getLabel() }}</td>
                            <td>{{ $lancamento->descricao }}</td>
                            <td>{{ $lancamento->nomeFavorecido ?? '—' }}</td>
                            <td>{{ $lancamento->vencimento?->format('d/m/Y') }}</td>
                            <td class="num">R$ {{ number_format($lancamento->valor, 2, ',', '.') }}</td>
                            <td class="num">R$ {{ number_format($lancamento->valorPago, 2, ',', '.') }}</td>
                            <td class="num">R$ {{ number_format($lancamento->valorRestante, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                    <tr class="subtotal">
                        <td colspan="4">Subtotal — {{ $grupo }}</td>
                        <td class="num">R$ {{ number_format($lancamentosDetalhados[$grupo]->sum('valor'), 2, ',', '.') }}</td>
                        <td class="num">R$ {{ number_format($lancamentosDetalhados[$grupo]->sum('valorPago'), 2, ',', '.') }}</td>
                        <td class="num">R$ {{ number_format($lancamentosDetalhados[$grupo]->sum('valorRestante'), 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">Nenhum lançamento encontrado para os filtros selecionados.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
