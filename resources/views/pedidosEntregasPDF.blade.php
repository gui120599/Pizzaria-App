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
        @php
            $pedidosEntregasFinalizados = $pedidos->filter(function ($pedido) {
                return in_array($pedido->pedido_status, ['ENTREGUE', 'FINALIZADO']) &&
                    in_array($pedido->pedido_opcaoentrega_id, ['3']) &&
                    !is_null($pedido->pedido_venda_id); // Verifica se o campo contém valor (não é null)
            });
        @endphp

        <div id="Tabela">

            {{-- Pedidos de Entregas e Finalizados --}}
            <p class="text-center text-[8px] font-bold" colspan="3">
                ------------------------------------------------------------------------------</p>
            <p class="font-bold">Pedidos Entregues e Finalizados</p>
            @if (count($pedidosEntregasFinalizados) > 0)
                <table class="w-full">
                    <thead>
                        <tr class="text-[9px]">
                            <th class="text-start">PEDIDOS</th>
                            <th>VENDA</th>
                            <th class="w-24">OPÇÃO DE ENTREGA</th>
                            <th class="text-end">VALOR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pedidosEntregasFinalizados as $pedido)
                            <tr class="border-y">
                                <td class="text-xs font-bold text-start">{{ $pedido->id }}</td>
                                <td class="text-[8px] text-center">{{ $pedido->pedido_venda_id }}</td>
                                <td class="text-[8px] text-center">{{ $pedido->opcaoEntrega->opcaoentrega_nome }} <br><span>{{ $pedido->pedido_endereco_entrega }}</span> </td>
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
                        <label>{{ count($pedidosEntregasFinalizados) }}</label><br>
                        <label>R$
                            {{ number_format($pedidosEntregasFinalizados->sum('pedido_valor_desconto'), 2, ',', '.') }}</label><br>
                        <label>R$
                            {{ number_format($pedidosEntregasFinalizados->sum('pedido_valor_total'), 2, ',', '.') }}</label><br>
                    </div>
                </div>
            @else
                <p class="text-[8px] text-center uppercase">Nenhum Pedido Entregue e Finalizado.</p>
            @endif


</body>
<script>
    //window.print();
</script>

</html>
