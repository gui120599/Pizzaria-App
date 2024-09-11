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
                                    {{ number_format($venda->venda_valor_total, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach

                    </tbody>
                </table>
                <div class="flex justify-between text-xs">
                    <div id="valores" class="text-left">
                        <label>Qtd. Vendas</label><br>
                        <label>Total Desconto</label><br>
                        <label>Total Vendas</label><br>
                    </div>
                    <div id="dados-valores" class="text-right font-bold">
                        <label>{{ count($vendas) }}</label><br>
                        <label>R$ {{ number_format($vendas->sum('venda_valor_desconto'), 2, ',', '.') }}</label><br>
                        <label>R$ {{ number_format($vendas->sum('venda_valor_total'), 2, ',', '.') }}</label><br>
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
                                @php
                                    $totalPorOpcaoPagamento = 0; // Inicializa o total por opção de pagamento
                                @endphp
                                @foreach ($pagamentos as $pg)
                                    @if ($pg->opcaoPagamento->id == $op_pg->id)
                                        @php
                                            // Apenas soma os pagamentos relacionados à sessão de caixa
                                            $totalPorOpcaoPagamento +=
                                                $pg->venda->venda_sessao_caixa_id == $sessao_caixa->id
                                                    ? $pg->pg_venda_valor_pagamento
                                                    : 0;
                                        @endphp
                                    @endif
                                @endforeach
                                <tr class="border-y">
                                    <td class="text-xs font-bold text-start">{{ $op_pg->opcaopag_nome }}</td>
                                    <td class="text-[9px] font-bold text-end">R$
                                        {{ number_format($totalPorOpcaoPagamento, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-[8px] text-center uppercase">Nenhuma Entrada.</p>
            @endif
            <div class="flex justify-between text-xs">
                <div id="valores" class="text-left">
                    <label>Total Troco</label><br>
                    <label>Total Recebido</label><br>
                </div>
                <div id="dados-valores" class="text-right font-bold">
                    <label>R$ {{ number_format($vendas->sum('venda_valor_troco'), 2, ',', '.') }}</label><br>
                    <label>R$ {{ number_format($pagamentos->sum('pg_venda_valor_pagamento'), 2, ',', '.') }}</label><br>
                </div>
            </div>
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
                                    <td class="text-xs font-bold text-start">{{ $saida->mov_descricao }}</td>
                                    <td class="text-[9px] font-bold text-end">R$
                                        {{ number_format($saida->mov_valor, 2, ',', '.') }}</td>
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
                    <label>Total Entradas</label><br>
                    <label>Total Saídas</label><br>
                    <label>Total Saldo Inicial</label><br>
                    <label>Total Saldo Final</label><br>
                </div>
                <div id="dados-valores" class="text-right font-bold">
                    <label>R$ {{ number_format($vendas->sum('venda_valor_total'), 2, ',', '.') }}</label><br>
                    <label>R$ {{ number_format($saidas->sum('mov_valor'), 2, ',', '.') }}</label><br>
                    <label>R$ {{ number_format($sessao_caixa->sessaocaixa_saldo_inicial, 2, ',', '.') }}</label><br>
                    <label>R$ {{ number_format($sessao_caixa->sessaocaixa_saldo_final, 2, ',', '.') }}</label><br>
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
