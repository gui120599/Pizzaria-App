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
        <p class="text-center font-bold mb-2">Sessão Pedidos</p>
        <div class="grid grid-cols-2 text-xs">
            @php

                $pedidosEntreguesFinalizados = $pedidos->filter(function ($pedido) {
                    return in_array($pedido->pedido_status, ['ENTREGUE', 'FINALIZADO']) &&
                        !is_null($pedido->pedido_venda_id); // Verifica se o campo contém valor (não é null)
                });

                $pedidosEntreguesNaoFinalizados = $pedidos->filter(function ($pedido) {
                    return in_array($pedido->pedido_status, ['ENTREGUE', 'FINALIZADO']) &&
                        is_null($pedido->pedido_venda_id); // Verifica se o campo contém valor (não é null)
                });

                $pedidosCancelados = $pedidos->filter(function ($pedido) {
                    return in_array($pedido->pedido_status, ['CANCELADO']);
                });

            @endphp
            <div class="col-span-1 flex flex-col text-left">
                <label class="text-lg">Nº de Pedidos</label>
                <label>Data/Hora 1º Pedido</label>
            </div>
            <div class="col-span-1 flex flex-col text-right font-bold">
                <label class="text-lg">
                    {{ $pedidos->filter(function ($pedido) {
                            return in_array($pedido->pedido_status, ['ENTREGUE', 'FINALIZADO']);
                        })->count() }}
                </label>

                @php
                    $primeiroPedido = $pedidos->firstWhere(function ($pedido) {
                        return in_array($pedido->pedido_status, ['ENTREGUE', 'FINALIZADO']);
                    });
                @endphp

                @if ($primeiroPedido)
                    <label>{{ $primeiroPedido->pedido_datahora_abertura->format('d/m/Y H:i') }}</label>
                @else
                    <label>Sem pedidos entregues ou finalizados</label>
                @endif

            </div>
        </div>

        <div id="Tabela">
            {{--Pedidos Entregues e Não Finalizados--}}
            <p class="text-center text-[8px]" colspan="3">
                ------------------------------------------------------------------------------</p>
            <p class="font-bold">Pedidos Entregues e Não Finalizados</p>
            @if (count($pedidosEntreguesNaoFinalizados) > 0)
                <table class="w-full">
                    <thead>
                        <tr class="text-[9px]">
                            <th class="text-start">PEDIDOS</th>
                            <th>VENDA</th>
                            <th class="text-end">VALOR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pedidosEntreguesNaoFinalizados as $pedido)
                            <tr class="border-y">
                                <td class="text-xs font-bold text-start">{{ $pedido->id }}</td>
                                <td class="text-[8px] text-center">{{ $pedido->pedido_venda_id }}</td>
                                <td class="text-[9px] font-bold text-end">R$
                                    {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</td>

                            </tr>
                        @endforeach

                    </tbody>
                </table>
                <div class="flex justify-between text-xs">
                    <div id="valores" class="text-left">
                        <label>Qtd. Pedidos</label><br>
                        <label>Total Desconto</label><br>
                        <label>Total Pedidos</label><br>
                    </div>
                    <div id="dados-valores" class="text-right font-bold">
                        <label>{{ count($pedidosEntreguesNaoFinalizados) }}</label><br>
                        <label>R$
                            {{ number_format($pedidosEntreguesNaoFinalizados->sum('pedido_valor_desconto'), 2, ',', '.') }}</label><br>
                        <label>R$
                            {{ number_format($pedidosEntreguesNaoFinalizados->sum('pedido_valor_total'), 2, ',', '.') }}</label><br>
                    </div>
                </div>
            @else
                <p class="text-[8px] text-center uppercase">Nenhum Pedido.</p>
            @endif

            {{--Pedidos Entregues e Finalizados--}}
            <p class="text-center text-[8px] font-bold" colspan="3">
                ------------------------------------------------------------------------------</p>
            <p class="font-bold">Pedidos Entregues e Finalizados</p>
            @if (count($pedidosEntreguesFinalizados) > 0)
                <table class="w-full">
                    <thead>
                        <tr class="text-[9px]">
                            <th class="text-start">PEDIDOS</th>
                            <th>VENDA</th>
                            <th class="text-end">VALOR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pedidosEntreguesFinalizados as $pedido)
                            <tr class="border-y">
                                <td class="text-xs font-bold text-start">{{ $pedido->id }}</td>
                                <td class="text-[8px] text-center">{{ $pedido->pedido_venda_id }}</td>
                                <td class="text-[9px] font-bold text-end">R$
                                    {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</td>

                            </tr>
                        @endforeach

                    </tbody>
                </table>
                <div class="flex justify-between text-xs">
                    <div id="valores" class="text-left">
                        <label>Qtd. Pedidos</label><br>
                        <label>Total Desconto</label><br>
                        <label>Total Pedidos</label><br>
                    </div>
                    <div id="dados-valores" class="text-right font-bold">
                        <label>{{ count($pedidosEntreguesFinalizados) }}</label><br>
                        <label>R$
                            {{ number_format($pedidosEntreguesFinalizados->sum('pedido_valor_desconto'), 2, ',', '.') }}</label><br>
                        <label>R$
                            {{ number_format($pedidosEntreguesFinalizados->sum('pedido_valor_total'), 2, ',', '.') }}</label><br>
                    </div>
                </div>
            @else
                <p class="text-[8px] text-center uppercase">Nenhum Pedido Entregue e Finalizado.</p>
            @endif

            {{--Pedidos Cancelados--}}
            <p class="text-center text-[8px] font-bold" colspan="3">
                ------------------------------------------------------------------------------</p>
            <p class="font-bold">Pedidos Cancelados</p>
            @if (count($pedidosCancelados) > 0)
                <table class="w-full">
                    <thead>
                        <tr class="text-[9px]">
                            <th class="text-start">PEDIDOS</th>
                            <th>Data/Hora Cancelamento</th>
                            <th class="text-end">VALOR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pedidosCancelados as $pedido)
                            <tr class="border-y">
                                <td class="text-xs font-bold text-start">{{ $pedido->id }}</td>
                                <td class="text-[8px] text-center">{{ $pedido->pedido_datahora_cancelado->format('d/m/Y H:i') }}</td>
                                <td class="text-[9px] font-bold text-end">R$
                                    {{ number_format($pedido->pedido_valor_total, 2, ',', '.') }}</td>

                            </tr>
                        @endforeach

                    </tbody>
                </table>
                <div class="flex justify-between text-xs">
                    <div id="valores" class="text-left">
                        <label>Qtd. Pedidos</label><br>
                        <label>Total Desconto</label><br>
                        <label>Total Pedidos</label><br>
                    </div>
                    <div id="dados-valores" class="text-right font-bold">
                        <label>{{ count($pedidosCancelados) }}</label><br>
                        <label>R$
                            {{ number_format($pedidosCancelados->sum('pedido_valor_desconto'), 2, ',', '.') }}</label><br>
                        <label>R$
                            {{ number_format($pedidosCancelados->sum('pedido_valor_total'), 2, ',', '.') }}</label><br>
                    </div>
                </div>
                <p class="text-center text-[8px] font-bold" colspan="3">
                    ------------------------------------------------------------------------------</p>
            @else
                <p class="text-[8px] text-center uppercase">Nenhum Pedido Cancelado.</p>
            @endif

</body>
<script>
    window.print();
</script>

</html>
