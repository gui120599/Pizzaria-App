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
    <div id="conteudo" class="p-1">
        <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="" class="w-28 mx-auto">
        <p class="text-center font-bold mb-2">Comprovante de Venda</p>
        @php
            $dataVenda = $venda->venda_datahora_finalizada ?? $venda->created_at;
        @endphp
        <div class="grid grid-cols-2 text-xs">
            <div class="col-span-1 flex flex-col text-left">
                <label class="text-lg">Nº Venda</label>
                <label>Cliente</label>
                <label>Operador</label>
                <label>Data/Hora</label>
            </div>
            <div class="col-span-1 flex flex-col text-right font-bold">
                <label class="text-lg">{{ $venda->id }}</label>
                <label class="truncate">{{ $venda->cliente?->cliente_nome ?? 'Não informado' }}</label>
                <label>{{ $venda->sessaoCaixa?->user?->name_first ?? 'S/A' }}</label>
                <label>{{ $dataVenda?->format('d/m/Y H:i') ?? '—' }}</label>
            </div>
        </div>
        <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>
        <div id="Tabela">
            <table class="w-full">
                <thead>
                    <tr class="text-sm">
                        <th>QTD</th>
                        <th>PRODUTO</th>
                        <th>VALOR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($venda->itensVenda as $item)
                        <tr class="">
                            @if ($item->item_venda_quantidade == 0.5)
                                <td class="text-xs font-bold text-center">MEIA</td>
                            @else
                                <td class="text-xs font-bold text-center">{{ $item->item_venda_quantidade }}</td>
                            @endif
                            <td class="text-xs text-center uppercase">
                                {{ $item->produto?->categoria?->categoria_nome }} {{ $item->produto?->produto_descricao }}
                                @if ($item->adicionaisItemVenda)
                                    @foreach ($item->adicionaisItemVenda as $adicional)
                                        <p class="text-xs font-bold">{{ $adicional->aiv_quantidade }} Adic. {{ $adicional->adicional?->adicional_nome }}</p>
                                    @endforeach
                                @endif
                                @if ($item->item_venda_observacao)
                                    <p class="text-xs font-bold">Obser. {{ $item->item_venda_observacao }}</p>
                                @endif
                            </td>
                            <td class="text-xs font-bold text-right">R$
                                {{ number_format($item->item_venda_valor, 2, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="text-center text-[8px]" colspan="3">
                                ------------------------------------------------------------------------------</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex justify-between text-xs">
            <div id="valores" class="text-left">
                <label>Qtd. Itens</label><br>
                <label>(+) Valor Produtos</label><br>
                @if ($venda->venda_valor_desconto > 0)
                    <label>(-) Desconto</label><br>
                @endif
                @if ($venda->venda_valor_acrescimo > 0)
                    <label>(+) Acréscimo</label><br>
                @endif
                @if ($venda->venda_valor_frete > 0)
                    <label>(+) Taxa de Entrega</label><br>
                @endif
                <label>(=) Valor Total</label><br>
                <label>Valor Pago</label><br>
                @if ($venda->venda_valor_troco > 0)
                    <label>Troco</label><br>
                @endif
            </div>
            <div id="dados-valores" class="text-right font-bold">
                <label>{{ $venda->itensVenda->sum('item_venda_quantidade') }}</label><br>
                <label>R$ {{ number_format($venda->venda_valor_itens, 2, ',', '.') }}</label><br>
                @if ($venda->venda_valor_desconto > 0)
                    <label>R$ {{ number_format($venda->venda_valor_desconto, 2, ',', '.') }}</label><br>
                @endif
                @if ($venda->venda_valor_acrescimo > 0)
                    <label>R$ {{ number_format($venda->venda_valor_acrescimo, 2, ',', '.') }}</label><br>
                @endif
                @if ($venda->venda_valor_frete > 0)
                    <label>R$ {{ number_format($venda->venda_valor_frete, 2, ',', '.') }}</label><br>
                @endif
                <label>R$ {{ number_format($venda->venda_valor_total, 2, ',', '.') }}</label><br>
                <label>R$ {{ number_format($venda->venda_valor_pago, 2, ',', '.') }}</label><br>
                @if ($venda->venda_valor_troco > 0)
                    <label>R$ {{ number_format($venda->venda_valor_troco, 2, ',', '.') }}</label><br>
                @endif
            </div>
        </div>
        @if ($venda->pagamentos->isNotEmpty())
            <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>
            <div id="formas-pagamento" class="text-xs">
                <label class="font-bold">Forma(s) de pagamento:</label><br>
                @foreach ($venda->pagamentos as $pagamento)
                    <label>{{ $pagamento->opcaoPagamento?->opcaopag_nome ?? '—' }}: R$
                        {{ number_format($pagamento->pg_venda_valor_pagamento, 2, ',', '.') }}</label><br>
                @endforeach
            </div>
        @endif
        <div class="mt-1 grid grid-cols-3">
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
