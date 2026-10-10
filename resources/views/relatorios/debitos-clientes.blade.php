<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <title>{{ $clienteFiltrado ? 'Extrato de débitos — '.$clienteFiltrado : 'Débitos de Clientes' }}</title>

    @vite(['resources/css/app.css'])

    <style>
        @media print {
            @page { size: A4; margin: 1.5cm; }
            .no-print { display: none !important; }
            .card, tr { break-inside: avoid; }
        }
    </style>
</head>

@php
    $brl = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
    $quantidade = fn (float $q): string => rtrim(rtrim(number_format($q, 3, ',', '.'), '0'), ',');
@endphp

<body class="bg-gray-100 text-gray-800 text-xs">
    <button onclick="window.print()"
        class="no-print fixed top-4 right-4 z-50 flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-lg hover:bg-gray-700">
        Imprimir
    </button>

    <div id="conteudo" class="mx-auto max-w-4xl bg-white p-8 shadow print:shadow-none print:p-0">
        <div class="flex items-center justify-between border-b-2 border-gray-800 pb-3 mb-6">
            <div class="flex items-center gap-4">
                <img src="{{ asset('img/Logo Pizzaria login.png') }}" alt="Logo" class="h-14">
                <div>
                    <h1 class="text-base font-bold">{{ $clienteFiltrado ? 'Extrato de débitos' : 'Débitos de Clientes' }}</h1>
                    <p class="text-gray-600">
                        {{ $clienteFiltrado ? 'Cliente: '.$clienteFiltrado : 'Títulos a receber em aberto' }}
                        @if ($somenteVencidos)
                            — somente vencidos
                        @endif
                        @if ($agruparPorMes)
                            — agrupado por mês
                        @endif
                        @if ($busca !== '')
                            — busca "{{ $busca }}"
                        @endif
                    </p>
                </div>
            </div>
            <div class="text-right text-gray-600">
                <p>Emitido em {{ $geradoEm->format('d/m/Y H:i') }}</p>
                <p>por {{ $geradoPor }}</p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-4 gap-3">
            <div class="card border border-gray-300 rounded-md p-3">
                <p class="text-[10px] uppercase text-gray-500">Total em aberto</p>
                <p class="text-base font-bold">{{ $brl($totais['total']) }}</p>
            </div>
            <div class="card border border-gray-300 rounded-md p-3">
                <p class="text-[10px] uppercase text-gray-500">Vencido</p>
                <p class="text-base font-bold">{{ $brl($totais['vencido']) }}</p>
            </div>
            <div class="card border border-gray-300 rounded-md p-3">
                <p class="text-[10px] uppercase text-gray-500">Clientes</p>
                <p class="text-base font-bold">{{ $totais['clientes'] }}</p>
            </div>
            <div class="card border border-gray-300 rounded-md p-3">
                <p class="text-[10px] uppercase text-gray-500">Títulos</p>
                <p class="text-base font-bold">{{ $totais['titulos'] }}</p>
            </div>
        </div>

        @forelse ($porCliente as $debito)
            <div class="mb-6">
                <div class="flex items-end justify-between border-b border-gray-300 pb-1 mb-2">
                    <div>
                        <h2 class="text-sm font-bold">{{ $debito['cliente']?->cliente_nome ?? 'Sem cliente' }}</h2>
                        <p class="text-gray-500">
                            {{ collect([
                                $debito['cliente']?->cliente_celular,
                                $debito['cliente']?->cliente_cpf ?? $debito['cliente']?->cliente_cnpj,
                                $debito['cliente']?->cliente_limite_credito !== null ? 'limite '.$brl((float) $debito['cliente']->cliente_limite_credito) : null,
                            ])->filter()->implode(' · ') }}
                        </p>
                    </div>
                    <p class="text-right">
                        <span class="text-sm font-bold">{{ $brl($debito['total']) }}</span>
                        @if ($debito['vencido'] > 0)
                            <span class="block text-red-700">vencido {{ $brl($debito['vencido']) }}</span>
                        @endif
                    </p>
                </div>

                <table class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="border p-1 text-left">Origem</th>
                            <th class="border p-1 text-right w-24">Valor</th>
                            <th class="border p-1 text-right w-24">Em aberto</th>
                        </tr>
                    </thead>
                    @php
                        $grupos = $agruparPorMes
                            ? \App\Services\DebitosClienteService::agruparPorMes($debito['titulos'])
                            : collect([['mes' => null, 'total' => $debito['total'], 'titulos' => $debito['titulos']]]);
                    @endphp
                    @foreach ($grupos as $grupo)
                    @if ($grupo['mes'])
                        <tbody>
                            <tr class="bg-gray-200 font-bold uppercase">
                                <td class="border p-1" colspan="2">{{ $grupo['mes'] }}</td>
                                <td class="border p-1 text-right">{{ $brl($grupo['total']) }}</td>
                            </tr>
                        </tbody>
                    @endif
                    @foreach ($grupo['titulos'] as $titulo)
                        <tbody>
                            <tr class="bg-gray-50">
                                <td class="border p-1 font-semibold">
                                    {{ $titulo['venda'] ? 'Venda #'.$titulo['venda']->id.' — '.$titulo['venda']->created_at?->format('d/m/Y H:i') : 'Título #'.$titulo['lancamento']->id }}
                                    <span class="font-normal text-gray-600">
                                        · vence {{ $titulo['lancamento']->vencimento?->format('d/m/Y') ?? '—' }}
                                        @if ($titulo['vencido'])
                                            <span class="font-semibold text-red-700">(vencido)</span>
                                        @endif
                                        @if ($titulo['recebido'] > 0)
                                            · recebido {{ $brl($titulo['recebido']) }}
                                        @endif
                                    </span>
                                </td>
                                <td class="border p-1 text-right">{{ $brl($titulo['valor']) }}</td>
                                <td class="border p-1 text-right font-semibold">{{ $brl($titulo['restante']) }}</td>
                            </tr>
                            @foreach ($titulo['pedidos'] as $linha)
                                @php
                                    $pedido = $linha['pedido'];
                                    // sessaoMesa tem withDefault: só é mesa se o pedido tiver sessão.
                                        $mesa = $pedido->pedido_sessao_mesa_id ? $pedido->sessaoMesa->mesa : null;
                                        $onde = $mesa ? ($mesa->mesa_nome ?? 'Mesa '.$mesa->mesa_numero) : $pedido->pedido_origem?->label();
                                @endphp
                                <tr>
                                    <td class="border p-1 pl-4">
                                        Pedido #{{ $pedido->id }}
                                        <span class="text-gray-600">
                                            @if ($onde) · {{ $onde }} @endif
                                            @if ($pedido->pedido_datahora_abertura) · {{ $pedido->pedido_datahora_abertura->format('d/m/Y H:i') }} @endif
                                        </span>
                                        @foreach ($linha['itens'] as $item)
                                            <span class="block pl-3 text-gray-600">{{ $quantidade((float) $item->item_pedido_quantidade) }}x {{ $item->ehMultiSabor() ? implode(' / ', $item->linhasSabores()) : $item->nomeProduto() }} — {{ $brl((float) $item->item_pedido_valor) }}</span>
                                        @endforeach
                                    </td>
                                    <td class="border p-1 text-right align-top">{{ $brl($linha['valor']) }}</td>
                                    <td class="border p-1 text-right align-top">{{ $brl($linha['em_aberto']) }}</td>
                                </tr>
                            @endforeach
                            @if ($titulo['ajuste'])
                                <tr>
                                    <td class="border p-1 pl-4 text-gray-600">{{ $titulo['ajuste']['descricao'] }}</td>
                                    <td class="border p-1 text-right">{{ $brl($titulo['ajuste']['valor']) }}</td>
                                    <td class="border p-1 text-right">{{ $brl($titulo['ajuste']['em_aberto']) }}</td>
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                    @endforeach
                </table>
            </div>
        @empty
            <p class="border p-4 text-center italic">Nenhum débito em aberto.</p>
        @endforelse

        <p class="mt-4 text-[10px] text-gray-500">
            "Em aberto" por pedido: saldo do título repartido na proporção do valor de cada pedido na venda.
        </p>
    </div>
</body>

</html>
