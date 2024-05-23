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
        <p class="text-center font-bold mb-2">Comanda de Mesa</p>
        <div class="grid grid-cols-2 text-xs">
            <div class="col-span-1 flex flex-col text-left">
                <label class="text-lg">Nº Mesa</label>
                <label>Cliente</label>
                <label>Garçom</label>
                <label>Chegada Data/Hora</label>
                <label class="text-base"></label>
                <label>Sessão Mesa</label>
            </div>
            <div class="col-span-1 flex flex-col text-right font-bold">
                <label class="text-lg">{{ $sessao_mesa->mesa->mesa_nome }}</label>
                <label class="truncate ...">{{ $sessao_mesa->cliente->cliente_nome }}</label>
                <label>{{ $sessao_mesa->garcom->name_first }}</label>
                <label>{{ $sessao_mesa->created_at->format('d/m/Y H:i') }}</label>
                <label>{{ $sessao_mesa->id }}</label>
            </div>
        </div>
        <p class="text-center text-[8px]" colspan="3">
            ------------------------------------------------------------------------------</p>
        <div id="Tabela">
            <table class="w-full">
                <thead>
                    <tr class="text-xs">
                        <th>QTD</th>
                        <th>PRODUTO</th>
                        <th>VALOR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($itens_inserido_pedido as $item)
                        <tr>
                            <td class="text-[8px]" colspan="3">Pedido: {{ $item->item_pedido_pedido_id }} -
                                {{ $item->pedido->pedido_datahora_abertura->format('d/m/Y H:i') }} - Garçom:
                                {{ $item->pedido->garcom->name_first }}</td>
                        </tr>
                        <tr class="">
                            @if ($item->item_pedido_quantidade == 0.5)
                            <td class="text-xs font-bold text-center">{{ $item->item_pedido_quantidade }} <br> <label class="text-[8px] font-bold text-left">(Meia)</label></td>
                                
                            @else
                                <td class="text-xs font-bold text-center">{{ $item->item_pedido_quantidade }}</td>
                            @endif
                            <td class="text-[10px] uppercase">{{ $item->produto->categoria->categoria_nome }} {{ $item->produto->produto_descricao }}
                                @if ($item->item_pedido_observacao)
                                    <p class="text-[8px]">{{ $item->item_pedido_observacao }}</p>
                                @endif
                            </td>
                            <td class="text-[9px] font-bold">R$ {{ number_format($item->item_pedido_valor, 2, ',', '.') }}</td>
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
            </div>
            <div id="dados-valores" class="text-right font-bold">
                <label>{{ $itens_inserido_pedido->sum('item_pedido_quantidade') }}</label><br>
                <label>R$ {{ number_format($pedidos->sum('pedido_valor_itens'), 2, ',', '.') }}</label><br>
                <label>R$ {{ number_format($pedidos->sum('pedido_valor_desconto'), 2, ',', '.') }}</label><br>
                <label>R$ {{ number_format($pedidos->sum('pedido_valor_total'), 2, ',', '.') }}</label><br>
            </div>
        </div>
        <p class="text-center font-bold">**COMPROVANTE NÃO FISCAL**</p>
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
