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
    <title>Relatório de Fechamento de Caixa</title>

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
                    <h1 class="text-base font-bold">Relatório de Fechamento de Caixa</h1>
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
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Indicadores</h2>
            <div class="grid grid-cols-4 gap-3">
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Faturamento</p>
                    <p class="text-base font-bold">{{ $brl($resumo['faturamento']) }}</p>
                    <p class="text-[10px] text-gray-500">{{ $resumo['vendas'] }} vendas · ticket médio {{ $brl($resumo['ticket_medio']) }}</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Receita operacional</p>
                    <p class="text-base font-bold">{{ $brl($resumo['receita_operacional']) }}</p>
                    <p class="text-[10px] text-gray-500">Sem a taxa de serviço ({{ $brl($resumo['taxa_servico']) }})</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Taxas das maquininhas</p>
                    <p class="text-base font-bold">{{ $brl($resumo['mdr']) }}</p>
                    <p class="text-[10px] text-gray-500">MDR efetivo {{ $pct($resumo['mdr_efetivo']) }} sobre {{ $brl($resumo['volume_maquininha']) }}</p>
                    @if ($resumo['sem_taxa'] > 0)
                        <p class="text-[10px] text-amber-700">{{ $resumo['sem_taxa'] }} pagamento(s) sem taxa cadastrada</p>
                    @endif
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Imposto da NFC-e</p>
                    <p class="text-base font-bold">{{ $brl($resumo['imposto_nfe']) }}</p>
                    <p class="text-[10px] text-gray-500">{{ $pct($resumo['imposto_efetivo']) }} sobre {{ $brl($resumo['faturamento_nfe']) }} com nota · {{ $brl($resumo['faturamento_sem_nfe']) }} sem nota autorizada</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Margem de contribuição</p>
                    <p class="text-base font-bold">{{ $brl($resumo['margem_contribuicao']) }}</p>
                    <p class="text-[10px] text-gray-500">{{ $pct($resumo['margem_percentual']) }} da receita operacional</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">CMV</p>
                    <p class="text-base font-bold">{{ $pct($resumo['cmv_percentual']) }}</p>
                    <p class="text-[10px] text-gray-500">{{ $brl($resumo['cmv']) }} · custo em {{ $pct($resumo['cobertura_custo'], 1) }} da receita</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Recebido</p>
                    <p class="text-base font-bold">{{ $brl($resumo['recebido']) }}</p>
                    <p class="text-[10px] text-gray-500">{{ $pct($resumo['participacao_maquininha'], 1) }} em maquininha · fiado {{ $brl($resumo['fiado']) }}</p>
                </div>
                <div class="card border border-gray-300 rounded-md p-3">
                    <p class="text-[10px] uppercase text-gray-500">Cancelamentos</p>
                    <p class="text-base font-bold">{{ $resumo['canceladas'] }}</p>
                    <p class="text-[10px] text-gray-500">{{ $brl($resumo['valor_cancelado']) }} · {{ $pct($resumo['taxa_cancelamento'], 1) }} das vendas</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="secao">
                <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Resultado das vendas (DRE)</h2>
                <table class="w-full border-collapse">
                    <tbody>
                        @foreach ($dre as $linha)
                            <tr class="{{ $linha['destaque'] ? 'bg-gray-100 font-bold' : '' }}">
                                <td class="border p-1">{{ $linha['descricao'] }}</td>
                                <td class="border p-1 text-right {{ $linha['valor'] < 0 ? 'text-red-700' : '' }}">{{ $brl($linha['valor']) }}</td>
                                <td class="border p-1 text-right">{{ $pct($linha['percentual']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="secao">
                <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Por forma de pagamento</h2>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Forma</th>
                            <th class="border p-1 text-right">Qtd.</th>
                            <th class="border p-1 text-right">Bruto</th>
                            <th class="border p-1 text-right">Mix</th>
                            <th class="border p-1 text-right">Taxa</th>
                            <th class="border p-1 text-right">Líquido</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($formas as $forma)
                            <tr>
                                <td class="border p-1">{{ $forma['forma'] }} <span class="text-gray-500">({{ $forma['categoria'] }})</span></td>
                                <td class="border p-1 text-right">{{ $forma['transacoes'] }}</td>
                                <td class="border p-1 text-right">{{ $brl($forma['bruto']) }}</td>
                                <td class="border p-1 text-right">{{ $pct($forma['participacao'], 1) }}</td>
                                <td class="border p-1 text-right text-red-700">{{ $brl($forma['taxa']) }}</td>
                                <td class="border p-1 text-right font-semibold">{{ $brl($forma['liquido']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="border p-2 text-center italic">Nenhum pagamento no recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

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
                            <td class="border p-1 text-right font-semibold">{{ $brl($linha['esperado']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['apurado']) }}</td>
                            <td class="border p-1 text-right font-semibold {{ $corDiferenca($linha['diferenca']) }}">{{ $brl($linha['diferenca']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-1 text-[10px] text-gray-500">Apurado e diferença consideram só as sessões que já têm fechamento.</p>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Por maquininha</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Maquininha</th>
                        <th class="border p-1 text-right">Qtd.</th>
                        <th class="border p-1 text-right">Débito</th>
                        <th class="border p-1 text-right">Crédito</th>
                        <th class="border p-1 text-right">Pix</th>
                        <th class="border p-1 text-right">Bruto</th>
                        <th class="border p-1 text-right">Taxa média</th>
                        <th class="border p-1 text-right">Taxa</th>
                        <th class="border p-1 text-right">Líquido</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($porMaquininha as $linha)
                        <tr>
                            <td class="border p-1 font-semibold">{{ $linha['maquininha'] }}</td>
                            <td class="border p-1 text-right">{{ $linha['transacoes'] }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['debito']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['credito']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['pix']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['bruto']) }}</td>
                            <td class="border p-1 text-right">{{ $pct($linha['taxa_percentual']) }}</td>
                            <td class="border p-1 text-right text-red-700">{{ $brl($linha['taxa']) }}</td>
                            <td class="border p-1 text-right font-semibold">{{ $brl($linha['liquido']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="border p-2 text-center italic">Nenhum pagamento em maquininha no recorte.</td></tr>
                    @endforelse
                </tbody>
                @if ($porMaquininha->isNotEmpty())
                    <tfoot>
                        <tr class="bg-gray-100 font-bold">
                            <td class="border p-1">Total</td>
                            <td class="border p-1 text-right">{{ $porMaquininha->sum('transacoes') }}</td>
                            <td class="border p-1 text-right">{{ $brl($porMaquininha->sum('debito')) }}</td>
                            <td class="border p-1 text-right">{{ $brl($porMaquininha->sum('credito')) }}</td>
                            <td class="border p-1 text-right">{{ $brl($porMaquininha->sum('pix')) }}</td>
                            <td class="border p-1 text-right">{{ $brl($resumo['volume_maquininha']) }}</td>
                            <td class="border p-1 text-right">{{ $pct($resumo['mdr_efetivo']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($resumo['mdr']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($resumo['volume_maquininha'] - $resumo['mdr']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Maquininhas por bandeira</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Maquininha</th>
                        <th class="border p-1 text-left">Tipo</th>
                        <th class="border p-1 text-left">Bandeira</th>
                        <th class="border p-1 text-right">Qtd.</th>
                        <th class="border p-1 text-right">Bruto</th>
                        <th class="border p-1 text-right">Ticket médio</th>
                        <th class="border p-1 text-right">Taxa</th>
                        <th class="border p-1 text-right">Valor taxa</th>
                        <th class="border p-1 text-right">Líquido</th>
                        <th class="border p-1 text-center">Recebe</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($maquininhas as $linha)
                        <tr>
                            <td class="border p-1">{{ $linha['maquininha'] }}</td>
                            <td class="border p-1">{{ $linha['tipo'] }}</td>
                            <td class="border p-1">{{ $linha['bandeira'] }}</td>
                            <td class="border p-1 text-right">{{ $linha['transacoes'] }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['bruto']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['ticket_medio']) }}</td>
                            <td class="border p-1 text-right {{ $linha['sem_taxa'] > 0 ? 'text-amber-700' : '' }}">
                                {{ $pct($linha['taxa_percentual']) }}@if ($linha['sem_taxa'] > 0)*@endif
                            </td>
                            <td class="border p-1 text-right text-red-700">{{ $brl($linha['taxa']) }}</td>
                            <td class="border p-1 text-right font-semibold">{{ $brl($linha['liquido']) }}</td>
                            <td class="border p-1 text-center">{{ $linha['prazo'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="border p-2 text-center italic">Nenhum pagamento em maquininha no recorte.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($resumo['sem_taxa'] > 0)
                <p class="mt-1 text-[10px] text-amber-700">* {{ $resumo['sem_taxa'] }} pagamento(s) sem taxa cadastrada para a maquininha/bandeira — entram com taxa 0.</p>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="secao">
                <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Por bandeira (todas as maquininhas)</h2>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Bandeira</th>
                            <th class="border p-1 text-right">Bruto</th>
                            <th class="border p-1 text-right">Part.</th>
                            <th class="border p-1 text-right">Taxa média</th>
                            <th class="border p-1 text-right">Taxa</th>
                            <th class="border p-1 text-right">Líquido</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bandeiras as $linha)
                            <tr>
                                <td class="border p-1 font-semibold">{{ $linha['bandeira'] }}</td>
                                <td class="border p-1 text-right">{{ $brl($linha['bruto']) }}</td>
                                <td class="border p-1 text-right">{{ $pct($linha['participacao'], 1) }}</td>
                                <td class="border p-1 text-right">{{ $pct($linha['taxa_percentual']) }}</td>
                                <td class="border p-1 text-right text-red-700">{{ $brl($linha['taxa']) }}</td>
                                <td class="border p-1 text-right font-semibold">{{ $brl($linha['liquido']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="border p-2 text-center italic">Nenhum pagamento em maquininha no recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="secao">
                <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Previsão de recebimento</h2>
                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Previsto para</th>
                            <th class="border p-1 text-left">Maquininha</th>
                            <th class="border p-1 text-right">Bruto</th>
                            <th class="border p-1 text-right">Taxa</th>
                            <th class="border p-1 text-right">A receber</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($previsao as $linha)
                            <tr>
                                <td class="border p-1 font-semibold">{{ $linha['data']?->format('d/m/Y') ?? 'Prazo não cadastrado' }}</td>
                                <td class="border p-1">{{ $linha['maquininha'] }}</td>
                                <td class="border p-1 text-right">{{ $brl($linha['bruto']) }}</td>
                                <td class="border p-1 text-right text-red-700">{{ $brl($linha['taxa']) }}</td>
                                <td class="border p-1 text-right font-semibold">{{ $brl($linha['liquido']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="border p-2 text-center italic">Nenhum pagamento em maquininha no recorte.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Movimentações de caixa</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Movimento</th>
                        <th class="border p-1 text-left">Forma</th>
                        <th class="border p-1 text-right">Qtd.</th>
                        <th class="border p-1 text-right">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movimentacoes as $linha)
                        <tr>
                            <td class="border p-1">{{ $linha['movimento'] }}</td>
                            <td class="border p-1">{{ $linha['forma'] }}</td>
                            <td class="border p-1 text-right">{{ $linha['quantidade'] }}</td>
                            <td class="border p-1 text-right font-semibold {{ $linha['valor'] < 0 ? 'text-red-700' : 'text-green-700' }}">{{ $brl($linha['valor']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="border p-2 text-center italic">Nenhuma movimentação nas sessões do recorte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="secao mb-6">
            <h2 class="text-sm font-bold border-b border-gray-300 pb-1 mb-3">Conferência por sessão</h2>
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="border p-1 text-left">Sessão</th>
                        <th class="border p-1 text-left">Caixa</th>
                        <th class="border p-1 text-left">Operador</th>
                        <th class="border p-1 text-left">Abertura</th>
                        <th class="border p-1 text-left">Fechamento</th>
                        <th class="border p-1 text-left">Conferência</th>
                        <th class="border p-1 text-right">Esperado</th>
                        <th class="border p-1 text-right">Apurado</th>
                        <th class="border p-1 text-right">Dif. dinheiro</th>
                        <th class="border p-1 text-right">Diferença</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conferencia as $linha)
                        <tr>
                            <td class="border p-1">#{{ $linha['sessao_id'] }}</td>
                            <td class="border p-1">{{ $linha['caixa'] }}</td>
                            <td class="border p-1">{{ $linha['operador'] }}</td>
                            <td class="border p-1">{{ $linha['abertura']?->format('d/m/Y H:i') }}</td>
                            <td class="border p-1">{{ $linha['fechamento']?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="border p-1">{{ $linha['status'] }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['esperado']) }}</td>
                            <td class="border p-1 text-right">{{ $brl($linha['apurado']) }}</td>
                            <td class="border p-1 text-right {{ $corDiferenca($linha['diferenca_dinheiro']) }}">{{ $brl($linha['diferenca_dinheiro']) }}</td>
                            <td class="border p-1 text-right font-semibold {{ $corDiferenca($linha['diferenca']) }}">{{ $brl($linha['diferenca']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="border p-2 text-center italic">Nenhuma sessão de caixa no recorte.</td></tr>
                    @endforelse
                </tbody>
            </table>
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
                <p class="mt-1 text-[10px] text-gray-500">Leitura do fechamento menos o saldo da abertura. Recebimento de fiado em cartão não identifica a maquininha.</p>
            </div>
        @endif
    </div>

    <script>
        window.print();
    </script>
</body>

</html>
