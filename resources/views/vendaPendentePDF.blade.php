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
        <p class="text-center font-bold mb-2">Comprovante de Débito em Aberto</p>

        <div class="grid grid-cols-2 text-xs">
            <div class="col-span-1 flex flex-col text-left">
                <label class="text-lg">Venda</label>
                <label>Cliente</label>
                <label>Vencimento</label>
                <label>Valor devido</label>
            </div>
            <div class="col-span-1 flex flex-col text-right font-bold">
                <label class="text-lg">#{{ $venda->id }}</label>
                <label class="truncate">{{ $venda->cliente?->cliente_nome ?? 'Não informado' }}</label>
                <label class="{{ $lancamento->esta_vencido ? 'text-red-600' : '' }}">
                    {{ $lancamento->vencimento?->format('d/m/Y') }}{{ $lancamento->esta_vencido ? ' (vencido)' : '' }}
                </label>
                <label class="text-base">R$ {{ number_format($lancamento->valor_restante, 2, ',', '.') }}</label>
            </div>
        </div>
        <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>

        @if ($pedidos_avulsos->isNotEmpty())
            <p class="text-xs font-bold uppercase mt-1">Pedidos</p>
            @foreach ($pedidos_avulsos as $pedido)
                <p class="text-xs font-semibold mt-1">Pedido #{{ $pedido->id }}</p>
                <table class="w-full">
                    <tbody>
                        @foreach ($pedido->item_pedido_pedido_id as $item)
                            <tr>
                                <td class="text-xs font-bold text-center w-8">{{ rtrim(rtrim(number_format((float) $item->item_pedido_quantidade, 3, ',', '.'), '0'), ',') }}</td>
                                <td class="text-xs uppercase">{{ $item->produto?->produto_descricao ?? '—' }}</td>
                                <td class="text-xs font-bold text-right w-16">R$ {{ number_format((float) $item->item_pedido_valor, 2, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach
            <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>
        @endif

        @if ($sessoes_mesa->isNotEmpty())
            <p class="text-xs font-bold uppercase mt-1">Mesas</p>
            @foreach ($sessoes_mesa as $sessaoMesa)
                <p class="text-xs font-semibold mt-1">{{ $sessaoMesa->mesa?->mesa_nome ?? 'Mesa' }} — Sessão #{{ $sessaoMesa->id }}</p>
                @foreach ($sessaoMesa->pedidos as $pedido)
                    <table class="w-full">
                        <tbody>
                            @foreach ($pedido->item_pedido_pedido_id as $item)
                                <tr>
                                    <td class="text-xs font-bold text-center w-8">{{ rtrim(rtrim(number_format((float) $item->item_pedido_quantidade, 3, ',', '.'), '0'), ',') }}</td>
                                    <td class="text-xs uppercase">{{ $item->produto?->produto_descricao ?? '—' }}</td>
                                    <td class="text-xs font-bold text-right w-16">R$ {{ number_format((float) $item->item_pedido_valor, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
            @endforeach
            <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>
        @endif

        @if ($produtos_avulsos->isNotEmpty())
            <p class="text-xs font-bold uppercase mt-1">Produtos lançados direto</p>
            <table class="w-full">
                <tbody>
                    @foreach ($produtos_avulsos as $linha)
                        <tr>
                            <td class="text-xs font-bold text-center w-8">{{ rtrim(rtrim(number_format($linha['quantidade'], 3, ',', '.'), '0'), ',') }}</td>
                            <td class="text-xs uppercase">{{ $linha['produto']?->produto_descricao ?? '—' }}</td>
                            <td class="text-xs font-bold text-right w-16">R$ {{ number_format($linha['valor'], 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>
        @endif

        <div class="flex justify-between text-xs mt-1">
            <label class="font-semibold">Total devido</label>
            <label class="font-bold text-base">R$ {{ number_format($lancamento->valor_restante, 2, ',', '.') }}</label>
        </div>

        <div class="mt-1 grid grid-cols-3">
            <div class="col-span-1 relative">
                <img src="{{ asset('img/qrcode insta.png') }}" alt="" class="absolute inset-x-0 left-0 w-16">
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
