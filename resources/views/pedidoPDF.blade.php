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
        @php
            $nomeOpcao      = $pedido->opcaoEntrega?->opcaoentrega_nome ?? '';
            $isEntrega      = str_contains(strtolower($nomeOpcao), 'entrega') || str_contains(strtolower($nomeOpcao), 'deliver');
            $isRetirada     = str_contains(strtolower($nomeOpcao), 'retirada');
            $abertura       = $pedido->pedido_datahora_abertura ?? $pedido->created_at;
        @endphp
        <div class="grid grid-cols-2 text-xs">
            @if ($isEntrega)
                <div class="col-span-1 flex flex-col text-left min-w-20">
                    <label class="text-lg">Nº Pedido</label>
                    <label>Cliente</label>
                    <label>Telefone</label>
                    <label>Atendente</label>
                    <label>Data/Hora</label>
                    <label class="text-base font-bold">Entregar em:</label>
                </div>
                <div class="col-span-1 flex flex-col text-right font-bold">
                    <label class="text-lg">{{ $pedido->id }}</label>
                    <label class="truncate">{{ $pedido->cliente?->cliente_nome ?? 'Não informado' }}</label>
                    <label>{{ $pedido->cliente?->cliente_celular ?? 'Não informado' }}</label>
                    <label>{{ $pedido->garcom?->name_first ?? 'S/A' }}</label>
                    <label>{{ $abertura->format('d/m/Y H:i') }}</label>
                    <label class="text-base">&nbsp;</label>
                </div>
                <p class="text-base text-center col-span-2 uppercase font-semibold max-w-72">
                    {{ $pedido->pedido_endereco_entrega ?? '—' }}
                </p>
                <div class="col-span-2 text-center mt-1">
                    <x-qrcode :data="$pedido->linkScanEntrega()" :size="140" class="mx-auto" />
                    <p class="text-[9px] font-bold mt-1">ESCANEIE PRA SAIR / CONFIRMAR ENTREGA</p>
                </div>
            @else

                <div class="col-span-1 flex flex-col text-left">
                    <label class="text-lg">Nº Pedido</label>
                    <label>Cliente</label>
                    @if ($isRetirada)
                        <label>Telefone</label>
                    @endif
                    <label>Atendente</label>
                    <label>Data/Hora</label>
                    <label>Tipo</label>
                    @if ($pedido->pedido_sessao_mesa_id !== null)
                        <label>Mesa / Sessão</label>
                    @endif
                </div>
                <div class="col-span-1 flex flex-col text-right font-bold">
                    <label class="text-lg">{{ $pedido->id }}</label>
                    <label class="truncate">{{ $pedido->cliente?->cliente_nome ?? 'Não informado' }}</label>
                    @if ($isRetirada)
                        @php
                            $tel = preg_replace('/\D/', '', $pedido->cliente?->cliente_celular ?? '');
                            $telFormatado = match(strlen($tel)) {
                                11 => '(' . substr($tel,0,2) . ') ' . substr($tel,2,5) . '-' . substr($tel,7),
                                10 => '(' . substr($tel,0,2) . ') ' . substr($tel,2,4) . '-' . substr($tel,6),
                                default => $pedido->cliente?->cliente_celular ?? 'Não informado',
                            };
                        @endphp
                        <label>{{ $telFormatado }}</label>
                    @endif
                    <label>{{ $pedido->garcom?->name_first ?? 'S/A' }}</label>
                    <label>{{ $abertura->format('d/m/Y H:i') }}</label>
                    <label>{{ $nomeOpcao ?: '—' }}</label>
                    @if ($pedido->pedido_sessao_mesa_id !== null)
                        <label>{{ $pedido->sessaoMesa?->mesa?->mesa_nome ?? '—' }} / {{ $pedido->pedido_sessao_mesa_id }}</label>
                    @endif
                </div>
            @endif
        </div>
        <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>
        @if ($pedido->pedido_observacao_pagamento !== null)
            <div id="obs-valores" class="text-center font-bold max-w-64">
                <label>OBSERVAÇÃO:</label><br>
                <label>{{ $pedido->pedido_observacao_pagamento }}</label>
            </div>
            <p class="text-center text-[8px]">------------------------------------------------------------------------------</p>
        @endif
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
                            <td class="text-xs text-center uppercase">
                                {{ $item->produto->categoria->categoria_nome }} {{ $item->produto->produto_descricao }}
                                @if ($item->adicionaisItemPedido)
                                    @foreach ($item->adicionaisItemPedido as $adicional)
                                        <p class="text-xs font-bold">{{ $adicional->aip_quantidade }} Adic. {{ $adicional->adicional->adicional_nome }}</p>
                                    @endforeach
                                @endif
                                @if ($item->item_pedido_observacao)
                                    <p class="text-xs font-bold">Obser. {{ $item->item_pedido_observacao }}</p>
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
                <label>(+) Valor Produtos</label><br>
                @if ($pedido->pedido_valor_desconto > 0)
                    <label>(-) Desconto</label><br>
                @endif
                @if (isset($pedido->pedido_valor_frete) && $pedido->pedido_valor_frete > 0)
                    <label>(+) Taxa de Entrega</label><br>
                @endif
                <label>(=) Valor Total</label><br>
                <label>Forma de Pagamento</label><br>
            </div>
            <div id="dados-valores" class="text-right font-bold">
                <label>{{ $itens_inserido_pedido->sum('item_pedido_quantidade') }}</label><br>
                <label>R$ {{ number_format($itens_inserido_pedido->sum('item_pedido_valor'), 2, ',', '.') }}</label><br>
                @if ($pedido->pedido_valor_desconto > 0)
                    <label>R$ {{ number_format($pedido->pedido_valor_desconto, 2, ',', '.') }}</label><br>
                @endif
                @if (isset($pedido->pedido_valor_frete) && $pedido->pedido_valor_frete > 0)
                    <label>R$ {{ number_format($pedido->pedido_valor_frete, 2, ',', '.') }}</label><br>
                @endif
                <label>R$ {{ number_format($itens_inserido_pedido->sum('item_pedido_valor') + ($pedido->pedido_valor_frete ?? 0), 2, ',', '.') }}</label><br>
                <label>{{ $pedido->pedido_descricao_pagamento ?? '—' }}</label><br>
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
    </div>
</body>
<script>
    window.print();
</script>

</html>
