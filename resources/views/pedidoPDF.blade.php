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
        <p class="text-center font-bold mb-2">Comanda de Pedido</p>
        <div class="grid grid-cols-2 text-xs">
            @if ($pedido->pedido_opcaoentrega_id == 3)
                <div class="col-span-1 flex flex-col text-left min-w-20">
                    <label class="text-lg">Nº Pedido</label>
                    <label>Cliente</label>
                    <label>Telefone</label>
                    <label>Atendente</label>
                    <label>Data/Hora</label>
                    <label class="text-base">Entregar</label>
                </div>
                <div class="col-span-1 flex flex-col text-right font-bold">
                    <label class="text-lg">{{ $pedido->id }}</label>
                    <label class=" truncate ...">{{ $pedido->cliente->cliente_nome }}</label>
                    <label>
                        @if ($pedido->cliente->cliente_celular)
                            {{ $pedido->cliente->cliente_celular }}
                        @else
                            Não Informado
                        @endif
                    </label>
                    <label>{{ $pedido->garcom->name_first }}</label>
                    <label>{{ $pedido->pedido_datahora_abertura->format('d/m/Y H:i') }}</label>
                </div>
                <p class="text-base text-center col-span-2 uppercase font-semibold max-w-72">
                    {{ $pedido->pedido_endereco_entrega }}</p>
            @else
                <div class="col-span-1 flex flex-col text-left">
                    <label class="text-lg">Nº Pedido</label>
                    <label>Cliente</label>
                    <label>Garçom</label>
                    <label>Data/Hora</label>
                    <label class="text-base">{{ $pedido->opcaoEntrega->opcaoentrega_nome }}</label>
                    @if ($pedido->pedido_sessao_mesa_id !== null)
                        <label>Sessão Mesa</label>
                    @endif
                </div>
                <div class="col-span-1 flex flex-col text-right font-bold">
                    <label class="text-lg">{{ $pedido->id }}</label>
                    <label class="truncate ...">{{ $pedido->cliente->cliente_nome }}</label>
                    <label>{{ $pedido->garcom->name_first }}</label>
                    <label>{{ $pedido->pedido_datahora_abertura->format('d/m/Y H:i') }}</label>
                    <label class="text-base">{{ $pedido->sessaoMesa->mesa->mesa_nome }}</label>
                    @if ($pedido->pedido_sessao_mesa_id !== null)
                        <label class="text-base">{{ $pedido->pedido_sessao_mesa_id }}</label>
                    @endif
                </div>
            @endif
        </div>
        <p class="text-center text-[8px]">------------------------------------------------------------------------------
        </p>
        @if ($pedido->pedido_opcaoentrega_id == 3 || $pedido->pedido_opcaoentrega_id == 2)
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
                        @foreach ($itens_inserido_pedido as $item)
                            <tr class="">
                                @if ($item->item_pedido_quantidade == 0.5)
                                    <td class="text-xs font-bold text-center">MEIA</td>
                                @else
                                    <td class="text-xs font-bold text-center">{{ $item->item_pedido_quantidade }}</td>
                                @endif
                                <td class="text-xs text-center uppercase">{{ $item->produto->produto_descricao }}
                                    @if ($item->item_pedido_observacao)
                                        <p class="text-xs font-bold">{{ $item->item_pedido_observacao }}</p>
                                    @endif
                                </td>
                                <td class="text-xs font-bold text-right">R$
                                    {{ number_format($item->item_pedido_valor, 2, ',', '.') }}</td>
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
                    <label>(+)Valor Produtos</label><br>
                    <label>(-)Desconto</label><br>
                    <label>(=)Valor Total</label><br>
                    <label>Forma Pagamento</label><br>
                </div>
                <div id="dados-valores" class="text-right font-bold">
                    <label>{{ $itens_inserido_pedido->sum('item_pedido_quantidade') }}</label><br>
                    <label>R$ {{ number_format($pedido->pedido_valor_itens,2,',','.') }}</label><br>
                    <label>R$ {{ number_format($pedido->pedido_valor_desconto,2,',','.') }}</label><br>
                    <label>R$ {{ number_format($pedido->pedido_valor_total,2,',','.') }}</label><br>
                    <label>{{ $pedido->pedido_descricao_pagamento }}</label><br>
                </div>
            </div>
            @if ($pedido->pedido_observacao_pagamento !== null)
                <div id="obs-valores" class="text-left font-bold max-w-64">
                    <label>Obs. de pagamento:</label><br>
                    <label>{{ $pedido->pedido_observacao_pagamento }}</label>
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
        @else
            <div id="Tabela">
                <table class="w-full">
                    <thead>
                        <tr>
                            <th>QTD</th>
                            <th>PRODUTO</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($itens_inserido_pedido as $item)
                        <tr class="">
                            @if ($item->item_pedido_quantidade == 0.5)
                                <td class="text-xs font-bold text-center">MEIA</td>
                            @else
                                <td class="text-xs font-bold text-center">{{ $item->item_pedido_quantidade }}</td>
                            @endif
                            <td class="text-xs text-center uppercase">{{ $item->produto->produto_descricao }}
                                @if ($item->item_pedido_observacao)
                                    <p class="text-xs font-bold">{{ $item->item_pedido_observacao }}</p>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-center text-[8px]" colspan="3">
                                ------------------------------------------------------------------------------</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</body>
<script>
    //window.print();
</script>
</html>
