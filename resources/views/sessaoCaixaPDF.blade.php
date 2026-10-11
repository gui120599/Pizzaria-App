<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="flex justify-center m-0">
    <div id="conteudo" class="p-1 mt-1">
        <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="" class="w-28 mx-auto">
        <p class="text-center font-bold mb-2">Sessão Caixa</p>
        <div class="grid grid-cols-2 text-xs">
            <div class="col-span-1 flex flex-col text-left">
                <label class="text-lg">Nº Sessão</label>
                <label>Caixa</label>
                <label>Funcionário</label>
                <label>Data/Hora Abertura</label>
                <label>Status</label>
            </div>
            <div class="col-span-1 flex flex-col text-right font-bold">
                <label class="text-lg">{{ $sessao_caixa->id }}</label>
                <label class="truncate ...">{{ $sessao_caixa->caixa->caixa_nome }}</label>
                <label>{{ $sessao_caixa->user->name_first }}</label>
                <label>{{ $sessao_caixa->created_at->format('d/m/Y H:i') }}</label>
                <label>{{ $sessao_caixa->sessaocaixa_status }}</label>
            </div>
        </div>
        <p class="text-center text-[8px]" colspan="3">
            ------------------------------------------------------------------------------</p>
        @php
            $brl = fn ($valor) => 'R$ ' . number_format((float) $valor, 2, ',', '.');
            $totalPagamentos = (float) $pagamentos->sum('pg_venda_valor_pagamento');
            $totalTroco = (float) $pagamentos->sum('pg_venda_valor_troco');
            $totalEntregue = $totalPagamentos + $totalTroco;
            $fiadoRecebidoCaixa = (float) $fiado_recebido->where('entra_no_caixa', true)->sum('total');
        @endphp
        <p class="font-bold">*Vendas</p>
        <div id="Tabela">
            @if (count($vendas) > 0)
                <table class="w-full">
                    <thead>
                        <tr class="text-[9px]">
                            <th class="text-start">VENDA</th>
                            <th>PEDIDOS</th>
                            <th>PAGAMENTOS</th>
                            <th>TROCO</th>
                            <th class="text-end">VALOR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vendas as $venda)
                            <tr class="border-y">
                                <td class="text-xs font-bold text-start">{{ $venda->id }}</td>
                                <td class="text-[8px] text-center">
                                    @foreach ($venda->pedidos as $pedido)
                                        Pedido: {{ $pedido->id }}<br>
                                    @endforeach
                                </td>
                                <td class="text-[8px] text-center">
                                    @foreach ($venda->pagamentos as $pg)
                                        {{ $pg->opcaoPagamento->opcaopag_nome }} - R$
                                        {{ number_format($pg->pg_venda_valor_pagamento, 2, ',', '.') }}<br>
                                    @endforeach
                                </td>
                                <td class="text-[9px] font-bold text-center">R$
                                    {{ number_format($venda->venda_valor_troco, 2, ',', '.') }}</td>
                                <td class="text-[9px] font-bold text-end">R$
                                    {{ number_format($venda->venda_valor_total, 2, ',', '.') }}
                                    @if ($venda->lancamentos->sum('valor') > 0)
                                        <br><span class="text-[8px] font-normal">a prazo: R$ {{ number_format($venda->lancamentos->sum('valor'), 2, ',', '.') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                    </tbody>
                </table>
                <div class="flex justify-between text-xs">
                    <div id="valores" class="text-left">
                        <label>Qtd. Vendas</label><br>
                        <label>Total Desconto</label><br>
                        <label>Total Vendas</label><br>
                        <label>(-) A prazo / fiado</label><br>
                        <label>Recebido em vendas</label><br>
                    </div>
                    <div id="dados-valores" class="text-right font-bold">
                        <label>{{ count($vendas) }}</label><br>
                        <label>{{ $brl($vendas->sum('venda_valor_desconto')) }}</label><br>
                        <label>{{ $brl($vendas->sum('venda_valor_total')) }}</label><br>
                        <label>{{ $brl($fiado_gerado) }}</label><br>
                        <label>{{ $brl($totalPagamentos) }}</label><br>
                    </div>
                </div>
                <p class="text-center text-[8px] font-bold" colspan="3">
                    ------------------------------------------------------------------------------</p>
                <p class="font-bold">*Pagamentos</p>
                <div id="Tabela">
                    <table class="w-full">
                        <thead>
                            <tr class="text-[9px]">
                                <th class="text-start">TIPO PAGAMENTO</th>
                                <th class="text-end">VALOR</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($opcoes_pagamentos as $op_pg)
                                <tr class="border-y">
                                    <td class="text-xs font-bold text-start">{{ $op_pg->opcaopag_nome }}</td>
                                    <td class="text-[9px] font-bold text-end">
                                        {{ $brl($pagamentos->where('pg_venda_opcaopagamento_id', $op_pg->id)->sum('pg_venda_valor_pagamento')) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-[8px] text-center uppercase">Nenhuma Entrada.</p>
            @endif
            @if ($fiado_recebido->isNotEmpty())
                <p class="text-center text-[8px]" colspan="3">
                    ------------------------------------------------------------------------------</p>
                <p class="font-bold">*Fiado recebido nesta sessão</p>
                <table class="w-full">
                    <tbody>
                        @foreach ($fiado_recebido as $recebimento)
                            <tr class="border-y">
                                <td class="text-xs font-bold text-start">{{ $recebimento['forma'] }}{{ $recebimento['entra_no_caixa'] ? '' : ' (fora do caixa)' }}</td>
                                <td class="text-[9px] font-bold text-end">{{ $brl($recebimento['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <p class="text-center text-[8px]" colspan="3">
                ------------------------------------------------------------------------------</p>
            <p class="font-bold">*Saídas</p>
            @if (count($saidas) > 0)
                <div id="Tabela">
                    <table class="w-full">
                        <thead>
                            <tr class="text-[9px]">
                                <th class="">SAIDA</th>
                                <th>DESCRIÇÃO</th>
                                <th>VALOR</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($saidas as $saida)
                                <tr class="border-y">
                                    <td class="text-xs font-bold text-start">{{ $saida->id }}</td>
                                    <td class="text-xs font-bold text-start">
                                        {{ $saida->mov_descricao }}
                                        @if ($saida->mov_motivo === null && $saida->mov_venda_id)
                                            <span class="text-[8px] font-normal">(estorno, já abatido da venda)</span>
                                        @endif
                                    </td>
                                    <td class="text-[9px] font-bold text-end">{{ $brl($saida->mov_valor) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-[8px] text-center uppercase">Nenhuma Saída.</p>
            @endif
            <p class="text-center text-[8px]" colspan="3">
                ------------------------------------------------------------------------------</p>
            <p class="font-bold">*Totais/Saldos</p>
            <div class="flex justify-between text-xs">
                <div id="valores" class="text-left">
                    <label>Entregue pelos clientes(+)</label><br>
                    <label>Troco devolvido(-)</label><br>
                    <label>Fiado recebido(+)</label><br>
                    <label>Saldo Inicial(+)</label><br>
                    <label>Suprimentos(+)</label><br>
                    <label>Saídas(-)</label><br>
                    <label class="font-bold">Saldo Final Esperado</label><br>
                </div>
                <div id="dados-valores" class="text-right font-bold">
                    <label>{{ $brl($totalEntregue) }}</label><br>
                    <label>{{ $brl($totalTroco) }}</label><br>
                    <label>{{ $brl($fiadoRecebidoCaixa) }}</label><br>
                    <label>{{ $brl($sessao_caixa->sessaocaixa_saldo_inicial) }}</label><br>
                    <label>{{ $brl($suprimentos) }}</label><br>
                    <label>{{ $brl($saidas_manuais) }}</label><br>
                    <label>{{ $brl(array_sum($esperado)) }}</label><br>
                </div>
            </div>
            <p class="text-center text-[8px]" colspan="3">
                ------------------------------------------------------------------------------</p>
            <p class="font-bold">*Esperado por forma</p>
            <div class="flex justify-between text-xs">
                <div id="valores" class="text-left">
                    <label>Dinheiro</label><br>
                    <label>Débito</label><br>
                    <label>Crédito</label><br>
                    <label>Pix (maquininhas)</label><br>
                    <label>PIX CNPJ</label><br>
                    @if (abs($esperado['outros']) >= 0.01)
                        <label>Outros</label><br>
                    @endif
                </div>
                <div id="dados-valores" class="text-right font-bold">
                    <label>{{ $brl($esperado['dinheiro']) }}</label><br>
                    <label>{{ $brl($esperado['debito']) }}</label><br>
                    <label>{{ $brl($esperado['credito']) }}</label><br>
                    <label>{{ $brl($esperado['pix']) }}</label><br>
                    <label>{{ $brl($esperado['pix_cnpj']) }}</label><br>
                    @if (abs($esperado['outros']) >= 0.01)
                        <label>{{ $brl($esperado['outros']) }}</label><br>
                    @endif
                </div>
            </div>
            <p class="text-center text-[8px]" colspan="3">
                ------------------------------------------------------------------------------</p><br>
            <div class="grid grid-cols-3">
                <div class="col-span-1 relative">
                    <img src="{{ asset('img/qrcode insta.png') }}" alt="QRCode Instagram Empório da Pizza"
                        class="absolute inset-x-0 left-0 w-16">
                </div>
                <div id="dados-empresa" class="col-span-2 text-right text-[10px]">
                    <label>EMPORIO DA PIZZA LTDA</label><br>
                    <label>CNPJ: 23.077.901/0001-87</label><br>
                    <label>WhatsApp: (64) 9 8145-3615</label><br>
                    <label>www.emporiodapizzago.com.br</label>
                </div>
            </div>
        </div>
</body>
<script>
    window.print();
</script>

</html>
