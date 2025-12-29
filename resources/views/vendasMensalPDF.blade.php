<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>Relatório de Vendas Mensais</title>

    @vite(['resources/css/app.css'])
</head>

<body class="flex justify-center m-0 text-xs">
    <div id="conteudo" class="p-1 mt-1 w-full max-w-md">

        <!-- Logo -->
        <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="Logo" class="w-28 mx-auto">
        <p class="text-center text-sm font-bold mb-2 uppercase">Relatório de Vendas Mensais</p>
        <div class="flex md:flex-col justify-between">
            <span class="text-start font-bold mb-2">Emissão: {{ \Carbon\Carbon::now()->format('d/m/Y H:i') }}</span>
            <span class="font-bold mb-2">Operador: {{ Auth::user()->name }}</span>
        </div>

        <!-- Tabela de Vendas -->
        <table class="w-full border border-gray-300">
            <thead>
                <tr class="bg-gray-200">
                    <th class="border p-1 text-left">Mês</th>
                    <th class="border p-1 text-right">Total de Vendas (R$)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($vendasMensais as $venda)
                    <tr>
                        <td class="border p-1 uppercase">
                            {{ \Carbon\Carbon::createFromFormat('Y-m-d', $venda->mes . '-01')->translatedFormat('F/Y') }}
                        </td>
                        <td class="border p-1 text-right font-bold">
                            R$ {{ number_format($venda->total_vendas, 2, ',', '.') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="border p-2 text-center italic">Nenhuma venda encontrada.</td>
                    </tr>
                @endforelse
                <tr class="bg-gray-100">
                    <td class="border p-1 font-bold">Total Geral</td>
                    <td class="border p-1 font-bold text-right">
                        R$ {{ number_format($vendasMensais->sum('total_vendas'), 2, ',', '.') }}
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Rodapé -->
        <!--<p class="text-center text-[8px] mt-3">------------------------------------------------------------</p>
        <div class="grid grid-cols-3 mt-3">
            <div class="col-span-1 relative">
                <img src="{{ asset('img/qrcode insta.png') }}" alt="QR Code Instagram" class="absolute w-16">
            </div>
            <div class="col-span-2 text-right text-[10px]">
                <label>EMPORIO DA PIZZA LTDA</label><br>
                <label>CNPJ: 23.077.901/0001-87</label><br>
                <label>WhatsApp: (64) 9 8145-3615</label><br>
                <label>www.emporiodapizzago.com.br</label>
            </div>
        </div>-->
    </div>

    <!-- Impressão Automática -->
    <script>
        window.print();
    </script>
</body>

</html>
