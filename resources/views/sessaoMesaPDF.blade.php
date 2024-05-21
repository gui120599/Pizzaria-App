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
        {{-- <img src="{{ asset('img/logo Pizzaria Preto.png') }}" alt="" class="w-28 mx-auto"> --}}
        <p class="text-center font-bold mb-2">Comanda de Mesa</p>
        <p class="text-center font-bold mb-2">**COMPROVANTE NÃO FISCAL**</p>
        <div class="grid grid-cols-2 text-xs">
            <div class="col-span-1 flex flex-col text-left">
                <label class="text-lg">Nº Mesa</label>
                <label>Garçom</label>
                <label>Data/Hora</label>
                <label class="text-base"></label>
                <label>Sessão Mesa</label>
            </div>
            <div class="col-span-1 flex flex-col text-right font-bold">
                <label class="text-lg">{{$sessao_mesa->mesa->mesa_nome}}</label>
                <label></label>
                <label></label>
                <label class="text-base"></label>
                <label class="text-base"></label>
            </div>
        </div>
        <p class="text-center">---------------------------------------</p>
        <div id="dados-cliente" class="text-left text-xs">
            <label class="font-bold">DADOS CLIENTE</label><br>
            <label>NOME.: </label><br>
            <label>FONE.: </label><br>
        </div>
        <p class="text-center">---------------------------------------</p>
        <div id="Tabela">
            <table class="w-full">
                <thead>
                    <tr>
                        <th>QTD</th>
                        <th>PRODUTO</th>
                        <th>VALOR</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($itens_inserido_pedido as $item)
                    <tr class="">
                        @if ($item->item_pedido_quantidade == 0.5)
                        <td class="text-lg font-bold text-left">Meia</td>
                        @else
                        <td class="text-lg font-bold text-left">{{ $item->item_pedido_quantidade }}</td>
                        @endif
                        <td class="text-center">{{ $item->produto->produto_descricao }}</td>
                        <td class="text-xs font-bold text-right">R$ {{ $item->item_pedido_valor }}</td>
                    </tr>
                    <tr>
                        <td class="text-center" colspan="3">---------------------------------------</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex justify-between text-xs">
            <div id="valores" class="text-left">
                <label>Qtd. Itens</label><br>
                <label>Valor Produtos R$</label><br>
                <label>Desconto R$</label><br>
                <label>Valor Total R$</label><br>
                <label>Forma Pagamento</label><br>
            </div>
            <div id="dados-valores" class="text-right font-bold">
                <label>{{ $itens_inserido_pedido->sum('item_pedido_quantidade') }}</label><br>
                <label></label><br>
                <label></label><br>
                <label></label><br>
                <label></label><br>
            </div>
        </div>
        <div id="obs-valores" class="text-left font-bold mb-12">
            <label>Obs. de pagamento:</label><br>
            <label></label>
        </div>
        <div class="grid grid-cols-3">
            <div class="col-span-1 relative">
                <img src="{{ asset('img/qrcode insta.png') }}" alt="QRCode Instagram Empório da Pizza" class="absolute inset-x-0 left-0 w-16">
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

</html>