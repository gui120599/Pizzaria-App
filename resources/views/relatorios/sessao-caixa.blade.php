@php
    $brl = fn (?float $valor): string => $valor === null ? '—' : 'R$ '.number_format($valor, 2, ',', '.');
    $pct = fn (float $valor, int $casas = 2): string => number_format($valor, $casas, ',', '.').'%';
    $corDiferenca = fn (?float $valor): string => $valor === null ? '' : (abs($valor) < 0.01 ? 'text-green-700' : 'text-red-700');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Relatório de Sessão de Caixa</title>

    @vite(['resources/css/app.css'])

    <style>
        @media print {
            @page { size: A4 landscape; margin: 1.2cm; }
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

    <div id="conteudo" class="mx-auto max-w-6xl bg-white p-8 shadow print:shadow-none print:p-0">
        <div class="flex items-center justify-between border-b-2 border-gray-800 pb-3 mb-6">
            <div class="flex items-center gap-4">
                <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="Logo" class="h-14">
                <div>
                    <h1 class="text-base font-bold">Relatório de Sessão de Caixa</h1>
                    <p class="text-gray-600">
                        @if ($sessoesSelecionadas)
                            Sessões: #{{ implode(', #', $sessoesSelecionadas) }}
                        @else
                            Período: {{ $periodo[0]->format('d/m/Y') }} a {{ $periodo[1]->format('d/m/Y') }}
                            @if ($caixaFiltrado)
                                — Caixa: {{ $caixaFiltrado }}
                            @endif
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
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Resumo</h2>
            <div class="grid grid-cols-5 gap-3">
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Vendido</p>
                    <p class="text-base font-bold">{{ $brl($resumo['total']) }}</p>
                    <p class="text-[10px] text-gray-500">{{ $resumo['vendas'] }} vendas finalizadas</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Recebido nas vendas</p>
                    <p class="text-base font-bold">{{ $brl($resumo['recebido']) }}</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Fiado gerado</p>
                    <p class="text-base font-bold">{{ $brl($resumo['fiado_gerado']) }}</p>
                    <p class="text-[10px] text-gray-500">Recebido de fiado: {{ $brl($resumo['fiado_recebido']) }}</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Troco fora do dinheiro</p>
                    <p class="text-base font-bold">{{ $brl($resumo['troco_fora_dinheiro']) }}</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Vendas com aviso</p>
                    <p class="text-base font-bold {{ $resumo['com_aviso'] > 0 ? 'text-red-700' : 'text-green-700' }}">{{ $resumo['com_aviso'] }}</p>
                </div>
            </div>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Vendas</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Venda</th>
                        <th class="border p-1 text-left">Finalizada</th>
                        <th class="border p-1 text-left">Cliente</th>
                        <th class="border p-1 text-left">Origem</th>
                        <th class="border p-1 text-left">Lançado</th>
                        <th class="border p-1 text-left">Pedido</th>
                        <th class="border p-1 text-right">Total</th>
                        <th class="border p-1 text-right">A prazo</th>
                        <th class="border p-1 text-left">Avisos / correções</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vendas as $venda)
                        <tr>
                            <td class="border p-1">#{{ $venda['venda_id'] }}</td>
                            <td class="border p-1 whitespace-nowrap">{{ $venda['finalizada_em']?->format('d/m H:i') }}</td>
                            <td class="border p-1">{{ $venda['cliente'] ?: '—' }}</td>
                            <td class="border p-1">{{ implode(', ', $venda['origens']) ?: 'Direto no PDV' }}</td>
                            <td class="border p-1">
                                @forelse ($venda['pagamentos'] as $pagamento)
                                    <div>{{ $pagamento['forma'] }} {{ $brl($pagamento['valor']) }}@if ($pagamento['troco'] > 0) · troco {{ $brl($pagamento['troco']) }} ({{ $pagamento['forma_troco'] }})@endif</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td class="border p-1">{{ implode(', ', $venda['formas_pedido']) ?: '—' }}</td>
                            <td class="border p-1 text-right">{{ $brl($venda['total']) }}</td>
                            <td class="border p-1 text-right">{{ $venda['fiado'] > 0 ? $brl($venda['fiado']) : '—' }}</td>
                            <td class="border p-1">
                                @foreach ($venda['avisos'] as $aviso)
                                    <div class="text-red-700">{{ $aviso['texto'] }}</div>
                                @endforeach
                                @foreach ($venda['correcoes'] as $correcao)
                                    <div class="text-blue-700">{{ $correcao }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="border p-2 text-center text-gray-500" colspan="9">Nenhuma venda no recorte.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($fiadoGerado->isNotEmpty() || $fiadoRecebido->isNotEmpty())
            <div class="secao mb-6">
                <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Fiado: gerado × recebido</h2>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Movimento</th>
                            <th class="border p-1 text-left">Cliente</th>
                            <th class="border p-1 text-left">Venda</th>
                            <th class="border p-1 text-left">Forma / situação</th>
                            <th class="border p-1 text-right">Valor</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fiadoGerado as $linha)
                            <tr>
                                <td class="border p-1">Gerado (a prazo)</td>
                                <td class="border p-1">{{ $linha['cliente'] ?: '—' }}</td>
                                <td class="border p-1">#{{ $linha['venda_id'] }}</td>
                                <td class="border p-1">{{ $linha['status'] }}</td>
                                <td class="border p-1 text-right">{{ $brl($linha['valor']) }}</td>
                            </tr>
                        @endforeach
                        @foreach ($fiadoRecebido as $linha)
                            <tr>
                                <td class="border p-1">Recebido na sessão #{{ $linha['sessao_id'] }}</td>
                                <td class="border p-1">{{ $linha['cliente'] ?: '—' }}</td>
                                <td class="border p-1">{{ $linha['venda_id'] ? '#'.$linha['venda_id'] : '—' }}</td>
                                <td class="border p-1">{{ $linha['forma'] }}{{ $linha['maquininha'] ? ' · '.$linha['maquininha'] : '' }}{{ $linha['entra_no_caixa'] ? '' : ' (fora do caixa)' }}</td>
                                <td class="border p-1 text-right">{{ $brl($linha['valor']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Fluxo de caixa por forma de pagamento</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Forma</th>
                        <th class="border p-1 text-right">Abertura</th>
                        <th class="border p-1 text-right">Vendas</th>
                        <th class="border p-1 text-right">Fiado recebido</th>
                        <th class="border p-1 text-right">Suprimentos</th>
                        <th class="border p-1 text-right">Saídas</th>
                        <th class="border p-1 text-right">Troco</th>
                        <th class="border p-1 text-right">Esperado</th>
                        <th class="border p-1 text-right">Apurado</th>
                        <th class="border p-1 text-right">Diferença</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($fluxo as $linha)
                        <tr>
                            <td class="border p-1 font-semibold">{{ $linha['categoria'] }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['abertura']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['vendas']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['fiado']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['suprimentos']) }}</td>
                            <td class="border p-1 text-right text-red-700">{{ $brl($linha['saidas']) }}</td>
                            <td class="border p-1 text-right text-red-700">{{ $brl($linha['troco']) }}</td>
                            <td class="border p-1 text-right font-semibold">{{ $brl($linha['esperado']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['apurado']) }}</td>
                            <td class="border p-1 text-right font-semibold {{ $corDiferenca($linha['diferenca']) }}">{{ $brl($linha['diferenca']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-1 text-[10px] text-gray-500">Vendas pelo valor que o cliente entregou; troco na forma em que foi devolvido. Apurado e diferença consideram só as sessões que já têm fechamento.</p>
        </div>

        @if ($conferenciaMaquininhas->isNotEmpty())
            <div class="secao">
                <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Conferência das maquininhas</h2>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Sessão</th>
                            <th class="border p-1 text-left">Maquininha</th>
                            <th class="border p-1 text-right">Sistema</th>
                            <th class="border p-1 text-right">Leitura</th>
                            <th class="border p-1 text-right">Dif. débito</th>
                            <th class="border p-1 text-right">Dif. crédito</th>
                            <th class="border p-1 text-right">Dif. Pix</th>
                            <th class="border p-1 text-right">Diferença</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($conferenciaMaquininhas as $linha)
                            <tr>
                                <td class="border p-1">#{{ $linha['sessao_id'] }}</td>
                                <td class="border p-1">{{ $linha['maquininha'] }}</td>
                                <td class="border p-1 text-right">{{ $brl($linha['sistema']) }}</td>
                                <td class="border p-1 text-right">{{ $brl($linha['leitura']) }}</td>
                                <td class="border p-1 text-right {{ $corDiferenca($linha['diferenca_debito']) }}">{{ $brl($linha['diferenca_debito']) }}</td>
                                <td class="border p-1 text-right {{ $corDiferenca($linha['diferenca_credito']) }}">{{ $brl($linha['diferenca_credito']) }}</td>
                                <td class="border p-1 text-right {{ $corDiferenca($linha['diferenca_pix']) }}">{{ $brl($linha['diferenca_pix']) }}</td>
                                <td class="border p-1 text-right font-semibold {{ $corDiferenca($linha['diferenca']) }}">{{ $brl($linha['diferenca']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-1 text-[10px] text-gray-500">Sistema = vendas e fiado recebido na maquininha. Leitura do fechamento menos o saldo da abertura; o PIX CNPJ é comparado com o extrato.</p>
            </div>
        @endif
    </div>

    <script>
        window.print();
    </script>
</body>

</html>
