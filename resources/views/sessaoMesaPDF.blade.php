<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Comanda de Mesa</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex justify-center m-0">
    <div id="conteudo" class="p-1 mt-1">

        {{-- Cabeçalho --}}
        <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="" class="w-28 mx-auto">
        <p class="text-center font-bold mb-2">{{ $titulo_impressao }}</p>

        <div class="grid grid-cols-2 text-xs mb-1">
            <div class="col-span-1 flex flex-col text-left">
                <label class="text-lg">Nº Mesa</label>
                <label>Cliente</label>
                <label>Garçom</label>
                <label>Chegada Data/Hora</label>
                <label>Sessão Mesa</label>
            </div>
            <div class="col-span-1 flex flex-col text-right font-bold">
                <label class="text-lg">{{ $sessao_mesa->mesa->mesa_nome }}</label>
                <label class="truncate">{{ $sessao_mesa->cliente->cliente_nome }}</label>
                <label>{{ $sessao_mesa->garcom->name_first }}</label>
                <label>{{ $sessao_mesa->created_at->format('d/m/Y H:i') }}</label>
                <label>{{ $sessao_mesa->id }}</label>
            </div>
        </div>

        <p class="text-center text-[8px]">------------------------------------------------------------------------</p>

        @php $totalGeral = 0; @endphp

        {{-- Blocos conforme a modalidade (PDFController::sessaoMesaPDF): por
             pessoa, por rodada ou um único bloco de itens agrupados. --}}
        @foreach ($blocos as $bloco)
            @php
                $subtotal = $bloco['subtotal'];
                $totalGeral += $subtotal;
            @endphp

            @if ($bloco['titulo'])
                <p class="text-xs font-bold uppercase mt-1">{{ $bloco['titulo'] }}</p>
            @endif
            @if ($bloco['subtitulo'])
                <p class="text-[10px]">{{ $bloco['subtitulo'] }}</p>
            @endif
            <p class="text-center text-[8px]">------------------------------------------------------------------------</p>

            <table class="w-full">
                <thead>
                    <tr class="text-xs">
                        <th class="text-left">QTD</th>
                        <th class="text-center">PRODUTO</th>
                        <th class="text-right">VALOR</th>
                    </tr>
                </thead>
                <tbody>
                    @if (isset($bloco['agrupados']))
                        @foreach ($bloco['agrupados'] as $grupo)
                            @include('sessao-mesa-pdf.linha-agrupada', ['grupo' => $grupo])
                        @endforeach
                    @else
                        @foreach ($bloco['itens'] as $item)
                            @include('sessao-mesa-pdf.linha-item', ['item' => $item])
                        @endforeach
                    @endif
                </tbody>
            </table>

            @if (count($blocos) > 1)
                <div class="flex justify-between text-xs font-bold mt-1 border-t border-dashed border-gray-400 pt-1">
                    <span>Subtotal {{ $bloco['titulo'] }}</span>
                    <span>R$ {{ number_format($subtotal, 2, ',', '.') }}</span>
                </div>
            @endif
            <p class="text-center text-[8px]">------------------------------------------------------------------------</p>

        @endforeach

        {{-- Total geral --}}
        @php
            // $totalGeral é a soma de item_pedido_valor, que já é LÍQUIDO (desconto embutido).
            // O bruto (antes do desconto) = líquido + desconto, para exibir o detalhamento.
            $totalDesconto = $total_desconto ?? 0;
            $totalBruto    = $totalGeral + $totalDesconto;
        @endphp
        <div class="flex justify-between text-xs mt-1">
            <span>(+) Valor Produtos</span>
            <span class="font-bold">R$ {{ number_format($totalBruto, 2, ',', '.') }}</span>
        </div>
        @if ($totalDesconto > 0)
        <div class="flex justify-between text-xs">
            <span>(-) Desconto</span>
            <span class="font-bold">R$ {{ number_format($totalDesconto, 2, ',', '.') }}</span>
        </div>
        @endif
        @php
            $taxaServico = \App\Support\ContaMesa::taxaServico($sessao_mesa, (float) $totalGeral);
        @endphp
        @if ($taxaServico > 0)
        <div class="flex justify-between text-xs">
            <span>(+) Taxa de Serviço ({{ number_format((float) $sessao_mesa->sessao_mesa_taxa_servico_percentual, 0) }}%)</span>
            <span class="font-bold">R$ {{ number_format($taxaServico, 2, ',', '.') }}</span>
        </div>
        @endif
        <div class="flex justify-between text-sm font-bold border-t-2 border-black pt-1 mt-1">
            <span>(=) TOTAL GERAL</span>
            <span>R$ {{ number_format($totalGeral + $taxaServico, 2, ',', '.') }}</span>
        </div>

        <p class="text-center font-bold mt-2">**COMPROVANTE NÃO FISCAL**</p>

        <div class="grid grid-cols-3 mt-2">
            <div class="col-span-1 relative">
                <img src="{{ asset('img/qrcode insta.png') }}" alt="QRCode Instagram" class="absolute inset-x-0 left-0 w-16">
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
<script>window.print();</script>
</html>
